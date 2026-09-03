<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\AI\Services\ResetCreditPeriodsService;
use Illuminate\Console\Command;

/**
 * Resets every tenant's AI credits whose period has elapsed.
 *
 * docs/08-AI-ARCHITECTURE.md §5 has specified this since the ledger shipped:
 * hourly, per-tenant anniversary, idempotent per period. The ledger method it
 * calls existed too. Neither was ever wired to a schedule, so no tenant's
 * allowance has reset since the feature launched.
 *
 * Hourly rather than daily, because tenant billing anniversaries fall on
 * whatever hour they signed up in, not midnight -- a daily run would leave
 * some tenants exhausted for up to 23 extra hours every month.
 */
final class ResetMonthlyCredits extends Command
{
    protected $signature = 'ai:reset-monthly-credits';

    protected $description = "Reset each tenant's AI credits once their monthly period has elapsed.";

    public function handle(ResetCreditPeriodsService $service): int
    {
        $result = $service->execute();

        $this->info(sprintf(
            'Checked %d account(s), reset %d.',
            $result['checked'],
            $result['reset'],
        ));

        return self::SUCCESS;
    }
}
