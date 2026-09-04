<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Publishing\Jobs\PublishPostTarget;
use App\Domain\Publishing\Models\PostTarget;
use App\Domain\Publishing\Services\ClaimPostTargetService;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Finds targets whose time has come and queues one job for each.
 *
 * This is the thing that was missing. PostTarget::due() and the whole
 * publishing engine were written and tested; nothing ever ran the query. A
 * scheduled post stayed scheduled.
 *
 * Also recovers targets whose worker died mid-publish. PostTarget::stale()
 * existed for exactly that and had no caller either, so a process killed
 * between claiming and publishing left the row locked for ever -- invisible
 * to due(), which only looks at scheduled rows.
 */
final class DispatchDuePublications extends Command
{
    protected $signature = 'publishing:dispatch-due
                            {--limit= : Most targets to queue in one pass}
                            {--dry-run : List what would be queued and queue nothing}';

    protected $description = 'Queue publishing jobs for targets that are due, and recover stale locks.';

    public function handle(ClaimPostTargetService $claims): int
    {
        $recovered = $this->recoverStale($claims);

        // Defaults to config('publishing.dispatch_batch_size') rather than a
        // second copy of the same number living in this signature.
        $limit = max(1, (int) ($this->option('limit')
            ?: config('publishing.dispatch_batch_size', 200)));

        /*
         | acrossTenants: a scheduled command has no request to resolve a
         | tenant from, and publishing is cross-tenant by definition -- every
         | agency's due posts go out on the same tick.
         */
        $targets = PostTarget::query()
            ->acrossTenants()
            ->due()
            /*
             | Suspended agencies do not publish.
             |
             | TenantStatus::permitsPublishing() has encoded this rule since
             | Phase 1 and had no caller: permitsProductAccess() is enforced by
             | EnsureTenantActive, but that is HTTP middleware, and publishing
             | runs on a schedule and a queue where no middleware applies. A
             | tenant who stopped paying kept receiving the core paid service.
             |
             | Grace is included or not by config, per docs/09-BILLING.md §5 --
             | cutting off a client's posts because an agency's card expired
             | damages a relationship grace exists to protect. Evaluated per
             | run so the flag takes effect without a deploy.
             |
             | Targets are LEFT scheduled rather than failed. Reinstating a
             | tenant should resume their schedule, not require them to rebuild
             | it.
             */
            ->whereIn('tenant_id', Tenant::query()
                ->whereIn('status', array_values(array_map(
                    static fn (TenantStatus $s): string => $s->value,
                    array_filter(
                        TenantStatus::cases(),
                        static fn (TenantStatus $s): bool => $s->permitsPublishing(),
                    ),
                )))
                ->select('id'))
            ->orderBy('scheduled_at')
            ->limit($limit)
            ->get();

        if ($this->option('dry-run')) {
            foreach ($targets as $target) {
                $this->line(sprintf(
                    '  #%d %s due %s',
                    $target->getKey(),
                    $target->provider_key,
                    $target->scheduled_at?->toDateTimeString() ?? '-',
                ));
            }

            $this->info("Dry run: {$targets->count()} target(s) would be queued, {$recovered} stale lock(s) would be released.");

            return self::SUCCESS;
        }

        $queued = 0;

        foreach ($targets as $target) {
            /*
             | Claim BEFORE dispatching, per docs/06-PUBLISHING-ENGINE.md §6.
             | The claim is the atomic step that makes double-publishing
             | impossible, so it belongs next to the decision to publish --
             | two dispatcher ticks racing on the same row leave exactly one
             | winner, and the loser never queues a job at all.
             */
            if (! $claims->claim($target)) {
                continue;
            }

            /*
             | Dispatched, not published here. The command decides WHAT is due;
             | a worker decides when it runs. Publishing inline would put a slow
             | provider call on the scheduler's tick and let one unresponsive
             | network hold up every other agency's posts.
             */
            PublishPostTarget::dispatch($target->tenant_id, $target->getKey());

            $queued++;
        }

        $this->info(sprintf(
            'Queued %d of %d due target(s); released %d stale lock(s).',
            $queued,
            $targets->count(),
            $recovered,
        ));

        return self::SUCCESS;
    }

    /**
     * Return targets whose worker died back to scheduled.
     *
     * Without this they stay `processing` for ever: due() only looks at
     * scheduled rows, so a lock nobody holds is a post nobody publishes and
     * nothing reports.
     */
    private function recoverStale(ClaimPostTargetService $claims): int
    {
        $stale = PostTarget::query()
            ->acrossTenants()
            ->stale()
            ->limit(200)
            ->get();

        foreach ($stale as $target) {
            $claims->release($target);

            /*
             | Logged rather than silent. A stale lock means a worker died
             | mid-publish, and the post may already be live on the platform --
             | the engine's duplicate detection handles that on the retry, but
             | somebody should be able to see it happened.
             */
            Log::warning('Released a stale publishing lock.', [
                'post_target_id' => $target->getKey(),
                'locked_by' => $target->locked_by,
                'locked_at' => $target->locked_at?->toIso8601String(),
            ]);
        }

        return $stale->count();
    }
}
