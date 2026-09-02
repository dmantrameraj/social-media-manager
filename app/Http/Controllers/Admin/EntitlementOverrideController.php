<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Billing\Entitlements\Enums\EntitlementType;
use App\Domain\Platform\Services\EntitlementOverrideService;
use App\Domain\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreEntitlementOverrideRequest;
use App\Http\Requests\Admin\TenantLifecycleRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;

/**
 * Per-tenant limit overrides.
 *
 * The riskiest screen on the admin surface: one number here silently changes
 * what a customer is entitled to, with no invoice and no plan change to
 * reconcile against. Hence the required reason and the full audit entry on
 * both create and remove.
 */
final class EntitlementOverrideController extends Controller
{
    public function __construct(private readonly EntitlementOverrideService $overrides) {}

    public function store(StoreEntitlementOverrideRequest $request, Tenant $tenant): RedirectResponse
    {
        $request->user()->can('platform.entitlements.override') || abort(403);

        $data = $request->validated();
        $type = EntitlementType::from($data['value_type']);

        $this->overrides->set(
            $tenant,
            $data['key'],
            $type,
            // An unlimited override carries no number; anything else must.
            $type === EntitlementType::Unlimited ? null : (int) $data['value'],
            $data['reason'],
            $request->user(),
            isset($data['expires_at']) ? Carbon::parse($data['expires_at']) : null,
        );

        return back()->with('status', "Override saved for {$data['key']}.");
    }

    public function destroy(TenantLifecycleRequest $request, Tenant $tenant, string $key): RedirectResponse
    {
        $request->user()->can('platform.entitlements.override') || abort(403);

        $this->overrides->clear($tenant, $key, $request->reason(), $request->user());

        return back()->with('status', "Override removed for {$key}. The plan value applies again.");
    }
}
