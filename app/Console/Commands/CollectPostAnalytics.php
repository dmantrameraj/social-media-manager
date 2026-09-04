<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Analytics\Services\RecordPostMetricsService;
use App\Domain\Publishing\Enums\TargetStatus;
use App\Domain\Publishing\Models\PostTarget;
use App\Domain\Social\Contracts\SupportsAnalytics;
use App\Domain\Social\Exceptions\UnknownProvider;
use App\Domain\Social\ProviderRegistry;
use App\Domain\Tenancy\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Polls published posts for their numbers.
 *
 * Analytics are not a snapshot. A post keeps accumulating for days, so this
 * runs repeatedly over a window and each run writes a fresh row -- which is
 * why the dashboard reads latestPerTarget() rather than summing everything.
 *
 * Only providers implementing SupportsAnalytics are polled. That interface has
 * existed since Phase 2 marked "Phase 5. Declared now so the capability model
 * is complete", with no implementer and no caller; this is the caller.
 */
final class CollectPostAnalytics extends Command
{
    protected $signature = 'analytics:collect
                            {--limit= : Most targets to poll in one pass}
                            {--days= : How far back a published post stays interesting}
                            {--dry-run : List what would be polled and poll nothing}';

    protected $description = 'Collect analytics for recently published posts.';

    public function handle(
        TenantContext $context,
        ProviderRegistry $providers,
        RecordPostMetricsService $recorder,
    ): int {
        $limit = max(1, (int) ($this->option('limit')
            ?: config('analytics.collect_batch_size', 200)));

        $days = max(1, (int) ($this->option('days')
            ?: config('analytics.window_days', 30)));

        /*
         | acrossTenants: a scheduled sweep has no request to resolve a tenant
         | from, and every agency's posts age on the same clock.
         |
         | Only published targets with an external id -- there is nothing to ask
         | a provider about a post it never accepted.
         */
        $targets = PostTarget::query()
            ->acrossTenants()
            ->with(['post', 'socialAccount'])
            ->where('status', TargetStatus::Published->value)
            ->whereNotNull('external_post_id')
            ->where('updated_at', '>=', now()->subDays($days))
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();

        if ($targets->isEmpty()) {
            $this->info('Nothing to collect.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            foreach ($targets as $target) {
                $this->line(sprintf('  #%d %s', $target->getKey(), $target->provider_key));
            }

            $this->info($targets->count().' would be polled.');

            return self::SUCCESS;
        }

        $collected = 0;
        $skipped = 0;

        foreach ($targets as $target) {
            try {
                $provider = $providers->for($target->provider_key);
            } catch (UnknownProvider) {
                // Not registered in this deployment. Not an error, and not
                // something to log once per target per run.
                $skipped++;

                continue;
            }

            /*
             | Capability, not configuration. A provider can be enabled and
             | still have no analytics API worth calling, and asking anyway
             | would spend rate limit to receive an error.
             */
            if (! $provider instanceof SupportsAnalytics) {
                $skipped++;

                continue;
            }

            $tenant = Tenant::query()->find($target->tenant_id);

            if ($tenant === null) {
                continue;
            }

            try {
                $context->run($tenant, function () use ($target, $provider, $recorder): void {
                    $raw = $provider->fetchPostAnalytics(
                        $target->socialAccount,
                        (string) $target->external_post_id,
                    );

                    /*
                     | The adapter returns normalised keys; the same array is
                     | kept as `raw`. When adapters begin returning a separate
                     | payload this is the seam that carries it.
                     */
                    $recorder->record($target, $raw, $raw);
                });

                $collected++;
            } catch (Throwable $e) {
                /*
                 | One account's failure must not end the sweep. Analytics are
                 | re-polled on the next run anyway, so a miss costs a few
                 | hours of freshness rather than the figure itself.
                 */
                $skipped++;

                Log::warning('Collecting analytics failed for a target.', [
                    'post_target_id' => $target->getKey(),
                    'provider' => $target->provider_key,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Collected {$collected}, skipped {$skipped}.");

        return self::SUCCESS;
    }
}
