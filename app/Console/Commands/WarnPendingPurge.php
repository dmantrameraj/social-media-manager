<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Services\PurgeWarningService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells agencies their data is about to be destroyed.
 *
 * Runs before platform:purge-expired-data rather than inside it: by the time a
 * tenant is due for purge, warning them is pointless. This command deals with
 * the tenants whose deadline has NOT yet arrived.
 */
final class WarnPendingPurge extends Command
{
    protected $signature = 'platform:warn-pending-purge
                            {--dry-run : List who would be warned and send nothing}';

    protected $description = 'Warn agencies whose retention deadline is approaching';

    public function handle(PurgeWarningService $warnings): int
    {
        /*
         | Every tenant with a live deadline. The service decides who is
         | actually due, because "which stages has this tenant crossed and not
         | been told about" is its business, not a query's.
         */
        $tenants = Tenant::query()
            ->whereNotNull('purge_after')
            ->whereNull('purged_at')
            ->get();

        if ($tenants->isEmpty()) {
            $this->info('No tenants are awaiting purge.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            foreach ($tenants as $tenant) {
                $this->line(sprintf(
                    '  #%d %s -- due %s, warned %s',
                    $tenant->getKey(),
                    $tenant->name,
                    $tenant->purge_after?->toDateString() ?? '-',
                    implode(', ', array_keys((array) ($tenant->purge_warnings_sent ?? []))) ?: 'never',
                ));
            }

            return self::SUCCESS;
        }

        $warned = 0;

        foreach ($tenants as $tenant) {
            try {
                $stage = $warnings->warn($tenant);

                if ($stage !== null) {
                    $warned++;
                    $this->line("  #{$tenant->getKey()} {$tenant->name} -- warned at the {$stage}-day mark");
                }
            } catch (Throwable $e) {
                /*
                 | One failure must not silence the rest. A warning that does
                 | not arrive is the difference between a policy and an ambush,
                 | and it is worse to skip several because one address bounced.
                 */
                $this->error("  #{$tenant->getKey()} failed: {$e->getMessage()}");

                Log::error('Purge warning failed.', [
                    'tenant_id' => $tenant->getKey(),
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        $this->info($warned === 0
            ? 'No warnings were due.'
            : "Warned {$warned} ".str('agency')->plural($warned).'.');

        return self::SUCCESS;
    }
}
