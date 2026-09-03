<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Services\PurgeExpiredTenantDataService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Destroys the data of every tenant whose retention clock has run out.
 *
 * The consumer docs/10-SECURITY.md §9 has always specified and nothing ever
 * implemented. Billing set tenants.purge_after on cancellation and
 * Tenant::duePurge() was ready to find them; between those two the retention
 * promise was a date in a column that nothing acted on.
 *
 * Daily, and deliberately quiet when there is nothing to do -- a retention job
 * that chatters is a retention job people stop reading.
 */
final class PurgeExpiredData extends Command
{
    protected $signature = 'platform:purge-expired-data
                            {--dry-run : List what would be purged and change nothing}
                            {--tenant= : Purge one tenant id, ignoring its due date}';

    protected $description = 'Purge data for tenants past their retention deadline';

    public function handle(PurgeExpiredTenantDataService $service): int
    {
        $tenants = $this->targets();

        if ($tenants->isEmpty()) {
            $this->info('Nothing is due for purge.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            /*
             | Offered because this is the least reversible thing the
             | application does. Anyone about to run it should be able to see
             | the list first without having to trust that they read the date
             | arithmetic correctly.
             */
            $this->warn("Dry run -- nothing will be changed.\n");

            foreach ($tenants as $tenant) {
                $this->line(sprintf(
                    '  #%d %s (due %s)',
                    $tenant->getKey(),
                    $tenant->name,
                    $tenant->purge_after?->toDateString() ?? 'forced',
                ));
            }

            return self::SUCCESS;
        }

        $purged = 0;

        foreach ($tenants as $tenant) {
            try {
                $counts = $service->purge($tenant);
                $purged++;

                $this->line(sprintf(
                    '  #%d %s -- %d connections, %d media, %d users, %d portal users',
                    $tenant->getKey(),
                    $tenant->name,
                    $counts['connections'],
                    $counts['media'],
                    $counts['users'],
                    $counts['portal_users'],
                ));
            } catch (Throwable $e) {
                /*
                 | One tenant failing must not stop the rest. A retention
                 | deadline is per-customer, and letting an unrelated failure
                 | hold everybody else's data past their date turns one bug into
                 | a compliance problem for every customer in the queue.
                 */
                $this->error("  #{$tenant->getKey()} failed: {$e->getMessage()}");

                Log::error('Tenant purge failed.', [
                    'tenant_id' => $tenant->getKey(),
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Purged {$purged} of {$tenants->count()}.");

        // A non-zero exit when any tenant failed, so a scheduler notices.
        return $purged === $tenants->count() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return Collection<int, Tenant>
     */
    private function targets()
    {
        // Tenant carries no tenant scope -- it is the thing being scoped by.
        $query = Tenant::query();

        if ($id = $this->option('tenant')) {
            /*
             | --tenant bypasses the due date so an erasure request can be
             | honoured immediately rather than waiting out the clock. It does
             | not bypass anything else: the same purge runs.
             */
            return $query->whereKey((int) $id)->get();
        }

        return $query->duePurge()->get();
    }
}
