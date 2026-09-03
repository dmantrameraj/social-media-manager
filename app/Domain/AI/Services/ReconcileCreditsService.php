<?php

declare(strict_types=1);

namespace App\Domain\AI\Services;

use App\Domain\AI\Credits\CreditLedger;
use App\Domain\AI\Models\AiCreditAccount;
use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Brings every tenant's cached credit balance back in line with its ledger.
 *
 * `CreditLedger::reconcile()` has existed since the ledger shipped and answers
 * "has this tenant's balance drifted", per-tenant. Nothing ever called it for
 * more than one tenant, and nothing ever acted on what it found: a cache made
 * wrong by a bad migration, a manual fix, or a bug elsewhere would stay wrong
 * indefinitely, silently understating or overstating what a tenant could
 * spend.
 *
 * See `CreditLedger::correctDrift()` for why correcting the cache writes no
 * ledger transaction: the ledger is the source of truth, so a drift means the
 * CACHE is wrong, not that credits were gained or lost.
 */
final class ReconcileCreditsService
{
    public function __construct(
        private readonly CreditLedger $ledger,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{checked: int, corrected: int}
     */
    public function execute(): array
    {
        $checked = 0;
        $corrected = 0;

        // acrossTenants: a scheduled sweep with no request, reaching every
        // tenant's account rather than one resolved from context.
        AiCreditAccount::query()
            ->acrossTenants()
            ->orderBy('id')
            ->chunkById(200, function ($accounts) use (&$checked, &$corrected): void {
                foreach ($accounts as $account) {
                    $checked++;

                    $tenant = Tenant::query()->find($account->tenant_id);

                    if ($tenant === null) {
                        continue;
                    }

                    try {
                        $result = $this->ledger->correctDrift($tenant);

                        if (! $result['corrected']) {
                            continue;
                        }

                        $corrected++;

                        /*
                         | Worth an audit entry precisely because it is
                         | unexpected: every write to `balance` is meant to
                         | already go through the ledger, so a correction here
                         | is evidence something bypassed it, which is what
                         | whoever reads this trail needs to go and find.
                         */
                        $this->audit->log(
                            'ai.credits_reconciled',
                            $tenant,
                            oldValues: ['balance' => $result['balance']],
                            newValues: ['balance' => $result['ledger'], 'drift' => $result['drift']],
                            tenantId: $tenant->getKey(),
                        );
                    } catch (Throwable $e) {
                        // One tenant's drift must not stop the sweep from
                        // reaching the rest.
                        Log::error('AI credit reconciliation failed.', [
                            'tenant_id' => $tenant->getKey(),
                            'exception' => $e->getMessage(),
                        ]);
                    }
                }
            });

        return ['checked' => $checked, 'corrected' => $corrected];
    }
}
