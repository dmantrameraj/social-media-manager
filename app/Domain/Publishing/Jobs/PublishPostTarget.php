<?php

declare(strict_types=1);

namespace App\Domain\Publishing\Jobs;

use App\Domain\Publishing\Enums\PostStatus;
use App\Domain\Publishing\Enums\TargetStatus;
use App\Domain\Publishing\Models\PostTarget;
use App\Domain\Publishing\Services\BuildPublishPayloadService;
use App\Domain\Publishing\Services\ClaimPostTargetService;
use App\Domain\Publishing\Services\PublishPostTargetService;
use App\Domain\Publishing\Workflow\PostStatusMachine;
use App\Domain\Tenancy\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Publishes ONE target to ONE account.
 *
 * The engine underneath has been complete and tested since Phase 3 --
 * claiming, retries with backoff, duplicate recovery, error classification --
 * and nothing ever called it. There was no job and no dispatcher, so a post
 * could be drafted, approved by the client and scheduled, and would then sit
 * there for ever. Twenty tests guarded a door nothing opened.
 *
 * One job per target rather than per post, which is the whole design: a
 * LinkedIn failure must not stop a Facebook post that was fine, and per-target
 * jobs get that from the queue rather than from careful bookkeeping.
 *
 * Shaped after docs/06-PUBLISHING-ENGINE.md §6, including the tenant id --
 * the dispatcher has already claimed the row, so this re-establishes context
 * and works inside the tenant scope rather than reaching across it.
 */
final class PublishPostTarget implements ShouldQueue
{
    use Queueable;

    /**
     * One attempt per dispatch.
     *
     * Retries are the ENGINE's business, not the queue's: it classifies the
     * failure, decides whether retrying can help, and sets next_attempt_at so
     * the dispatcher picks the target up again later. Queue-level retries
     * would run a second attempt immediately, ignoring that decision and
     * spending the budget the engine was rationing.
     */
    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $targetId,
    ) {
        $this->onQueue((string) config('publishing.queue', 'publishing'));
    }

    public function handle(
        TenantContext $context,
        ClaimPostTargetService $claims,
        PublishPostTargetService $publisher,
        BuildPublishPayloadService $payloads,
        PostStatusMachine $machine,
    ): void {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null) {
            // Purged between dispatch and pickup. Nothing left to publish to.
            return;
        }

        /*
         | Context re-established rather than scoped around: everything below
         | -- the target, its post, that post's media -- is then read through
         | the ordinary tenant scope, so a bug in this job cannot reach another
         | agency's rows. run() restores the previous context afterwards.
         */
        $context->run($tenant, function () use ($tenant, $claims, $publisher, $payloads, $machine): void {
            $target = PostTarget::query()
                ->with(['post.media', 'socialAccount'])
                ->find($this->targetId);

            if ($target === null) {
                return;
            }

            /*
             | The dispatcher claimed this row before queueing it. Anything
             | other than `processing` means somebody else already dealt with
             | it -- a redelivered message, or a stale lock the sweeper
             | released -- and publishing again would double-post.
             */
            if ($target->status !== TargetStatus::Processing) {
                return;
            }

            /*
             | Checked here as well as in the dispatcher, because a job can sit
             | on the queue across a suspension: the tenant was publishable
             | when this was claimed and may not be when it runs.
             |
             | Released rather than failed, which is what release() is for --
             | the work returns to the queue without consuming an attempt, so
             | reinstating the tenant resumes the schedule instead of making
             | them rebuild it.
             */
            if (! $tenant->permitsPublishing()) {
                $claims->release($target);

                return;
            }

            try {
                $publisher->execute($target, $payloads->execute($target));
            } catch (Throwable $e) {
                /*
                 | The engine handles provider failures itself and returns a
                 | status. Reaching here means something else broke -- a bug, a
                 | missing provider, storage gone. Release the claim so the row
                 | is retried rather than sitting locked until the stale sweep,
                 | then let the job fail loudly.
                 */
                $claims->release($target);

                Log::error('Publishing a target failed outside the engine.', [
                    'post_target_id' => $this->targetId,
                    'exception' => $e->getMessage(),
                ]);

                throw $e;
            }

            $this->settlePost($target, $machine);
        });
    }

    /**
     * Move the post to whatever its targets now say it is.
     *
     * Post::deriveStatusFromTargets() has existed since Phase 3 and was called
     * only by tests, so nothing ever wrote the result back: a post whose
     * targets had all published stayed `processing` on the dashboard, the
     * calendar and the client's portal indefinitely.
     *
     * Through the status machine rather than a direct write, so the approval
     * row and audit entry land in the same transaction -- the Definition of
     * Done asks the audit log to record every transition, and a system
     * transition is still a transition.
     */
    private function settlePost(PostTarget $target, PostStatusMachine $machine): void
    {
        $post = $target->post->fresh();

        if ($post === null) {
            return;
        }

        $post->load('targets');

        $derived = $post->deriveStatusFromTargets();

        // Still in flight, or already where it should be.
        if ($derived === $post->status || $derived === PostStatus::Processing) {
            return;
        }

        try {
            // No actor: assertCan() treats a null actor as a system
            // transition, which is exactly what the engine is.
            $machine->transition($post, $derived);
        } catch (Throwable $e) {
            /*
             | A post that cannot legally reach the derived status is worth
             | knowing about, but it must not fail the job -- the target
             | published, and re-running would attempt it a second time.
             */
            Log::warning('Could not settle a post after publishing.', [
                'post_id' => $post->getKey(),
                'derived' => $derived->value,
                'from' => $post->status->value,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
