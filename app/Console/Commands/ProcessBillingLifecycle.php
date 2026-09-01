<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Billing\Subscriptions\SubscriptionLifecycleService;
use Illuminate\Console\Command;

/**
 * Hourly, from the scheduler.
 *
 * Hourly rather than daily because tenants' billing anniversaries fall at all
 * hours, and a daily run would leave some accounts up to 23 hours past their
 * transition.
 */
final class ProcessBillingLifecycle extends Command
{
    protected $signature = 'billing:process-lifecycle';

    protected $description = 'Advance trials, billing periods and grace windows.';

    public function handle(SubscriptionLifecycleService $lifecycle): int
    {
        $result = $lifecycle->run();

        $this->info(sprintf(
            'Trials expired: %d. Periods expired: %d. Tenants suspended: %d.',
            $result['trial_expired'],
            $result['period_expired'],
            $result['suspended'],
        ));

        return self::SUCCESS;
    }
}
