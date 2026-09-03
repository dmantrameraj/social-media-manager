<?php

declare(strict_types=1);

namespace App\Domain\AI\Services;

use App\Domain\AI\Credits\CreditLedger;
use App\Domain\AI\Models\AiCreditAccount;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Resets every tenant's AI credits once their monthly period has elapsed.
 *
 * `CreditLedger::resetPeriod()` has existed since the ledger shipped and does
 * exactly this for one tenant. Nothing ever called it for more than one, so no
 * tenant's allowance has ever reset on schedule: a tenant who exhausted their
 * credits in month one stayed exhausted in month two, three, and every month
 * after, with no way to get more short of a manual admin adjustment.
 *
 * Per-tenant anniversary, not a fixed calendar day: `resetPeriod()` reads each
 * account's own `period_end` and is a no-op until it has passed, so running
 * this hourly against every account is what makes each tenant's reset land on
 * the date they actually started, not the 1st for everyone.
 */
final class ResetCreditPeriodsService
{
    public function __construct(private readonly CreditLedger $ledger) {}

    /**
     * @return array{checked: int, reset: int}
     */
    public function execute(): array
    {
        $checked = 0;
        $reset = 0;

        /*
         | acrossTenants: this runs on a schedule with no request, and the
         | whole point is to reach every tenant's account, not one.
         */
        AiCreditAccount::query()
            ->acrossTenants()
            ->whereNotNull('period_end')
            ->where('period_end', '<=', now())
            ->orderBy('id')
            ->chunkById(200, function ($accounts) use (&$checked, &$reset): void {
                foreach ($accounts as $account) {
                    $checked++;

                    $tenant = Tenant::query()->find($account->tenant_id);

                    if ($tenant === null) {
                        // A tenant deleted out from under its credit account is
                        // somebody else's cleanup; this sweep does not chase it.
                        continue;
                    }

                    try {
                        // resetPeriod() re-checks periodHasElapsed() itself and
                        // is idempotent via its own unique key, so a row that
                        // slipped between the query above and this call, or a
                        // retried run, cannot double-grant.
                        if ($this->ledger->resetPeriod($tenant) !== null) {
                            $reset++;
                        }
                    } catch (Throwable $e) {
                        /*
                         | One tenant's reset failing must not stop the rest --
                         | a shared exception would leave every tenant after the
                         | failing one still exhausted for another hour.
                         */
                        Log::error('AI credit period reset failed.', [
                            'tenant_id' => $tenant->getKey(),
                            'exception' => $e->getMessage(),
                        ]);
                    }
                }
            });

        return ['checked' => $checked, 'reset' => $reset];
    }
}
