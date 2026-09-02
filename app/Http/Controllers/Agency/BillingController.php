<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agency;

use App\Domain\AI\Credits\CreditLedger;
use App\Domain\Billing\Entitlements\EntitlementResolver;
use App\Support\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Shows the plan, its limits and current usage.
 *
 * Deliberately reachable while a tenant is suspended -- EnsureTenantActive
 * excludes billing routes, because locking someone out of the only screen that
 * could restore their account turns a recoverable lapse into a cancellation.
 */
final class BillingController
{
    public function __invoke(
        Request $request,
        TenantContext $context,
        EntitlementResolver $entitlements,
        CreditLedger $ledger,
    ): View {
        // Plan, spend and usage are commercially sensitive: a Designer has no
        // business seeing them. Reachable while suspended, but still gated.
        $request->user()->can('billing.view') || abort(403);

        $tenant = $context->get();

        $keys = ['brands.max', 'social_accounts.max', 'team_members.max', 'portal_users.max'];
        $usage = [];

        foreach ($keys as $key) {
            $entitlement = $entitlements->value($tenant, $key);

            $usage[] = [
                'key' => $key,
                'label' => (string) (((array) config('entitlements.keys'))[$key]['label'] ?? $key),
                'used' => $entitlements->currentUsage($tenant, $key),
                'limit' => $entitlement->isUnlimited() ? null : $entitlement->limit(),
                // Showing where a limit came from turns "why can they do that?"
                // into a one-glance answer during support.
                'source' => $entitlement->source,
            ];
        }

        return view('agency.billing', [
            'title' => 'Billing',
            'tenant' => $tenant,
            'usage' => $usage,
            'credits' => rescue(fn () => $ledger->accountFor($tenant), null, false),
        ]);
    }
}
