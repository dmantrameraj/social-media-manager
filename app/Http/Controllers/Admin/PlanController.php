<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Plans and what they grant.
 *
 * Read-only in V1. Editing a plan changes what every tenant on it is entitled
 * to, retroactively and without an invoice to reconcile against, so it is a
 * migration rather than a form -- and a half-built editor here would be the
 * most dangerous screen in the product. Per-tenant differences go through
 * entitlement overrides, which are scoped to one account and audited.
 */
final class PlanController extends Controller
{
    public function index(Request $request): View
    {
        $request->user()->can('platform.plans.manage') || abort(403);

        $plans = DB::table('plans')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $features = DB::table('plan_features')
            ->orderBy('key')
            ->get()
            ->groupBy('plan_id');

        $prices = DB::table('plan_prices')
            ->get()
            ->groupBy('plan_id');

        $subscriberCounts = DB::table('subscriptions')
            ->whereNull('deleted_at')
            ->whereIn('status', ['trialing', 'active', 'past_due', 'grace'])
            ->selectRaw('plan_id, COUNT(*) as aggregate')
            ->groupBy('plan_id')
            ->pluck('aggregate', 'plan_id');

        return view('admin.plans.index', [
            'title' => 'Plans',
            'plans' => $plans,
            'features' => $features,
            'prices' => $prices,
            'subscriberCounts' => $subscriberCounts,
        ]);
    }
}
