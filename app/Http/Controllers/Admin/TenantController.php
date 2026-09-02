<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Billing\Entitlements\EntitlementResolver;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\ImpersonationSession;
use App\Domain\Platform\Services\EntitlementOverrideService;
use App\Domain\Platform\Services\TenantLifecycleService;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Services\ProvisionTenantService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreTenantRequest;
use App\Http\Requests\Admin\TenantLifecycleRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Cross-tenant agency management.
 *
 * Every read here is deliberately unscoped -- that is the point of the surface
 * -- so each action still authorises against a platform gate. Middleware
 * proves the caller is a Super Admin; the gate proves this particular power
 * was intended to be theirs, which is what makes a later split into narrower
 * staff roles a config change rather than a rewrite.
 */
final class TenantController extends Controller
{
    public function __construct(
        private readonly TenantLifecycleService $lifecycle,
        private readonly EntitlementResolver $entitlements,
        private readonly EntitlementOverrideService $overrides,
    ) {}

    public function index(Request $request): View
    {
        $request->user()->can('platform.tenants.manage') || abort(403);

        $search = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', '');

        $tenants = Tenant::query()
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($q) use ($search): void {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%");
                });
            })
            ->when(
                TenantStatus::tryFrom($status) !== null,
                fn ($query) => $query->where('status', $status),
            )
            ->withCount('customers')
            ->with('owner')
            ->orderByDesc('created_at')
            ->paginate((int) config('platform.per_page.tenants', 25))
            ->withQueryString();

        return view('admin.tenants.index', [
            'title' => 'Agencies',
            'tenants' => $tenants,
            'search' => $search,
            'status' => $status,
            'statuses' => TenantStatus::cases(),
        ]);
    }

    public function show(Request $request, Tenant $tenant): View
    {
        $request->user()->can('platform.tenants.manage') || abort(403);

        return view('admin.tenants.show', [
            'title' => $tenant->name,
            'tenant' => $tenant->load('owner'),
            'members' => $tenant->users()->withPivot('status')->get(),
            'subscription' => DB::table('subscriptions')
                ->leftJoin('plans', 'plans.id', '=', 'subscriptions.plan_id')
                ->where('subscriptions.tenant_id', $tenant->getKey())
                ->whereNull('subscriptions.deleted_at')
                ->orderByDesc('subscriptions.id')
                ->select('subscriptions.*', 'plans.name as plan_name')
                ->first(),
            'usage' => $this->usage($tenant),
            'overrides' => $this->overrides->forTenant($tenant),
            'entitlementKeys' => array_keys((array) config('entitlements.keys', [])),
            'credits' => DB::table('ai_credit_accounts')
                ->where('tenant_id', $tenant->getKey())
                ->first(),
            'creditHistory' => DB::table('ai_credit_transactions')
                ->where('tenant_id', $tenant->getKey())
                ->orderByDesc('id')
                ->limit(10)
                ->get(),
            'impersonations' => ImpersonationSession::query()
                ->where('tenant_id', $tenant->getKey())
                ->with('superAdmin')
                ->orderByDesc('started_at')
                ->limit(10)
                ->get(),
            /*
             | Impersonation targets. Super Admins are excluded at the query
             | level as well as in ImpersonationService, so the UI never offers
             | an action that is going to be refused.
             */
            'impersonationTargets' => $tenant->users()
                ->where('users.is_super_admin', false)
                ->get(),
        ]);
    }

    public function create(Request $request): View
    {
        $request->user()->can('platform.tenants.manage') || abort(403);

        return view('admin.tenants.create', ['title' => 'New agency']);
    }

    /**
     * Manual provisioning, the sales flow: an agency signed a contract offline
     * and needs an account without going through self-service registration.
     */
    public function store(StoreTenantRequest $request, ProvisionTenantService $provision): RedirectResponse
    {
        $request->user()->can('platform.tenants.manage') || abort(403);

        $data = $request->validated();

        $tenant = DB::transaction(function () use ($data, $provision, $request): Tenant {
            $owner = User::query()->where('email', $data['owner_email'])->first();

            if ($owner === null) {
                $owner = User::query()->forceCreate([
                    'name' => $data['owner_name'],
                    'email' => $data['owner_email'],
                    // A random password the admin never sees. The owner sets
                    // their own through the password-reset flow, so staff never
                    // hold a customer's credential.
                    'password' => bcrypt(bin2hex(random_bytes(32))),
                    'status' => 'active',
                    'timezone' => $data['timezone'] ?? config('app.timezone'),
                    'email_verified_at' => now(),
                ]);
            }

            $tenant = $provision->execute($owner, $data['name']);

            $this->lifecycle->activate(
                $tenant,
                $data['reason'],
                $request->user(),
            );

            return $tenant;
        });

        return redirect()
            ->route('admin.tenants.show', $tenant)
            ->with('status', "Created {$tenant->name}. The owner can set a password using the reset link.");
    }

    public function suspend(TenantLifecycleRequest $request, Tenant $tenant): RedirectResponse
    {
        $request->user()->can('platform.tenants.manage') || abort(403);

        $this->lifecycle->suspend($tenant, $request->reason(), $request->user());

        return back()->with('status', "{$tenant->name} is suspended.");
    }

    public function reactivate(TenantLifecycleRequest $request, Tenant $tenant): RedirectResponse
    {
        $request->user()->can('platform.tenants.manage') || abort(403);

        $this->lifecycle->reactivate($tenant, $request->reason(), $request->user());

        return back()->with('status', "{$tenant->name} is active again.");
    }

    /**
     * Live limits with their provenance.
     *
     * Provenance is the whole value: "why can this tenant create 100 brands?"
     * is answered by the source column, not by the number.
     *
     * @return list<array{key: string, used: int, limit: string, source: string}>
     */
    private function usage(Tenant $tenant): array
    {
        $rows = [];

        foreach (array_keys((array) config('entitlements.keys', [])) as $key) {
            $entitlement = $this->entitlements->value($tenant, (string) $key);

            $rows[] = [
                'key' => (string) $key,
                'used' => $this->entitlements->currentUsage($tenant, (string) $key),
                'limit' => $entitlement->isUnlimited()
                    ? 'unlimited'
                    : (string) $entitlement->value,
                'source' => $entitlement->source,
            ];
        }

        return $rows;
    }
}
