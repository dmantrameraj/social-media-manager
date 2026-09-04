<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Social\Models\SocialConnection;
use App\Domain\Social\Services\RefreshConnectionService;
use App\Domain\Tenancy\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Renews grants that are about to expire.
 *
 * The other half of a mechanism that was fully built and never driven.
 * `SocialConnection::scopeNeedingRefresh()` existed with no caller, `refresh()`
 * is on the provider contract and implemented by every adapter, and
 * `social.refresh_lead_time` has been configured since the social tables were
 * created. Nothing ran the query, so an access token reached its expiry and
 * publishing began failing with no way back except an agency noticing.
 *
 * Hourly rather than per-minute: the lead time is measured in a day, so a
 * missed hour costs nothing and a per-minute sweep would hammer providers to
 * re-discover the same answer.
 */
final class RefreshSocialTokens extends Command
{
    protected $signature = 'social:refresh-tokens
                            {--limit= : Most connections to renew in one pass}
                            {--lead= : Seconds before expiry to act, overriding config}
                            {--dry-run : List what would be renewed and renew nothing}';

    protected $description = 'Renew social connections whose access tokens are close to expiring.';

    public function handle(TenantContext $context, RefreshConnectionService $service): int
    {
        $limit = max(1, (int) ($this->option('limit')
            ?: config('social.refresh_batch_size', 100)));

        $lead = $this->option('lead') !== null
            ? max(0, (int) $this->option('lead'))
            : null;

        /*
         | acrossTenants: a scheduled sweep has no request to resolve a tenant
         | from, and expiry is cross-tenant by definition -- every agency's
         | tokens age on the same clock.
         */
        $connections = SocialConnection::query()
            ->acrossTenants()
            ->needingRefresh($lead)
            ->orderBy('expires_at')
            ->limit($limit)
            ->get();

        if ($connections->isEmpty()) {
            $this->info('Nothing needs renewing.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            foreach ($connections as $connection) {
                $this->line(sprintf(
                    '  #%d %s expires %s',
                    $connection->getKey(),
                    $connection->provider_key,
                    $connection->expires_at?->toDateTimeString() ?? '-',
                ));
            }

            $this->info($connections->count().' would be renewed.');

            return self::SUCCESS;
        }

        $renewed = 0;
        $failed = 0;

        foreach ($connections as $connection) {
            $tenant = Tenant::query()->find($connection->tenant_id);

            if ($tenant === null) {
                // Purged between the query and this line. Nothing to renew for.
                continue;
            }

            /*
             | One tenant's failure must not end the sweep. A provider outage
             | affecting one agency would otherwise leave every later
             | connection unrenewed -- and those are sorted by expiry, so the
             | ones skipped are the most urgent.
             */
            try {
                $ok = $context->run(
                    $tenant,
                    fn (): bool => $service->refresh($connection),
                );

                $ok ? $renewed++ : $failed++;
            } catch (Throwable $e) {
                $failed++;

                Log::error('Renewing a connection threw.', [
                    'social_connection_id' => $connection->getKey(),
                    'provider' => $connection->provider_key,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Renewed {$renewed}, failed {$failed}.");

        return self::SUCCESS;
    }
}
