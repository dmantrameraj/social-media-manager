<?php

declare(strict_types=1);

namespace App\Domain\Publishing\Services;

use App\Domain\Audit\AuditLogger;
use App\Domain\Publishing\Enums\PostStatus;
use App\Domain\Publishing\Enums\TargetStatus;
use App\Domain\Publishing\Exceptions\CannotRetry;
use App\Domain\Publishing\Models\PostTarget;
use App\Domain\Publishing\Workflow\PostStatusMachine;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * Putting a failed target back in the queue.
 *
 * The engine has always been able to retry: post_targets carries attempts,
 * max_attempts and next_attempt_at, publication_attempts records every try,
 * and ProviderErrorClass decides what is worth retrying. The posts.retry
 * permission has been in the catalogue since Step 5.
 *
 * None of it was reachable. A post that failed showed one sentence of
 * explanation and then sat there — an agency whose client's content did not go
 * out could see that it had not gone out and do nothing about it. That is the
 * only kind of gap in this repository that costs money on the day it happens.
 *
 * WHAT MAY NOT BE RETRIED, AND WHY
 *
 * Only Failed. Not NeedsVerification: that state exists precisely because a
 * worker died mid-publish and nobody knows whether the post actually went out.
 * Retrying it is the blind retry docs/06-PUBLISHING-ENGINE.md refuses to do,
 * and resolving it safely needs SupportsRecentPostLookup, which no real
 * provider implements yet. A second copy on a client's feed is worse than a
 * post that is still marked failed.
 *
 * Not Published, Cancelled or Skipped either — those are settled.
 */
final class RetryPostTargetService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PostStatusMachine $machine,
    ) {}

    /**
     * @throws CannotRetry
     */
    public function execute(PostTarget $target, ?Authenticatable $actor = null): PostTarget
    {
        if ($target->status !== TargetStatus::Failed) {
            throw CannotRetry::status($target->status);
        }

        $was = [
            'status' => $target->status->value,
            'attempts' => $target->attempts,
            'error' => $target->last_error_code,
        ];

        DB::transaction(function () use ($target): void {
            $target->forceFill([
                'status' => TargetStatus::Scheduled->value,

                /*
                 | The attempt counter is reset, not incremented.
                 |
                 | max_attempts bounds how hard the ENGINE tries on its own. A
                 | person clicking retry is a new decision by somebody who has
                 | read the error -- usually after fixing the thing that caused
                 | it -- and giving them one grudging attempt out of an
                 | exhausted budget would mean the button does nothing.
                 */
                'attempts' => 0,

                // Cleared so the sweeper picks it up on its next pass rather
                // than honouring a backoff computed for the failure.
                'next_attempt_at' => null,

                // The old failure is not this attempt's failure. Leaving it
                // would show a stale error beside a target that is queued.
                'last_error_class' => null,
                'last_error_code' => null,
                'last_error_message' => null,

                // Any stale lock is released: the worker that held it is gone.
                'locked_at' => null,
                'locked_by' => null,
            ])->save();
        });

        $this->settlePost($target, $actor);

        $this->audit->log(
            action: 'post_target.retried',
            auditable: $target->post,
            oldValues: $was,
            newValues: ['status' => TargetStatus::Scheduled->value, 'attempts' => 0],
            actor: $actor,
        );

        return $target->refresh();
    }

    /**
     * Bring the post back in line with its targets.
     *
     * A post reads Failed or PartiallyPublished because every target had
     * settled. One of them is now queued again, so the post is Scheduled —
     * otherwise the calendar shows a failed post that is about to publish.
     *
     * Through the machine, so the change is legal, permitted and recorded.
     * The scheduling entitlement counts DISTINCT posts, so a retry of a post
     * already counted this period costs nothing — a tenant at their limit can
     * still recover a failure they have already paid for.
     */
    private function settlePost(PostTarget $target, ?Authenticatable $actor): void
    {
        $post = $target->post->fresh();

        if ($post === null) {
            return;
        }

        $retryable = [PostStatus::Failed, PostStatus::PartiallyPublished];

        if (! in_array($post->status, $retryable, true)) {
            return;
        }

        $this->machine->transition(
            $post,
            PostStatus::Scheduled,
            $actor,
            'Retried after a failure.',
        );
    }
}
