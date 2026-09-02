<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\AI\Credits\CreditLedger;
use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdjustCreditsRequest;
use Illuminate\Http\RedirectResponse;

/**
 * Manual AI credit correction.
 *
 * There is no path that edits a balance directly -- CreditLedger::adjust
 * writes a transaction, so the balance always equals the sum of its history
 * and a correction can be explained rather than only observed.
 */
final class CreditController extends Controller
{
    public function __construct(
        private readonly CreditLedger $ledger,
        private readonly AuditLogger $audit,
    ) {}

    public function store(AdjustCreditsRequest $request, Tenant $tenant): RedirectResponse
    {
        $request->user()->can('platform.credits.adjust') || abort(403);

        $data = $request->validated();
        $delta = (int) $data['delta'];

        $transaction = $this->ledger->adjust(
            $tenant,
            $delta,
            $data['reason'],
            $request->user()->getKey(),
        );

        /*
         | The ledger records the movement; the audit log records the decision.
         | They are separate trails on purpose -- one answers "what is this
         | balance made of", the other "which member of staff did this, from
         | where, and why".
         */
        $this->audit->log(
            'ai.credits_adjusted_by_admin',
            $tenant,
            newValues: [
                'delta' => $delta,
                'reason' => $data['reason'],
                'transaction_id' => $transaction->getKey(),
            ],
            actor: $request->user(),
            tenantId: $tenant->getKey(),
        );

        $direction = $delta >= 0 ? 'Granted' : 'Removed';

        return back()->with('status', "{$direction} ".abs($delta)." credits for {$tenant->name}.");
    }
}
