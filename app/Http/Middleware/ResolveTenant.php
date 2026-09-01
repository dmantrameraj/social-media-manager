<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Tenancy\Models\TenantUser;
use App\Support\TenantContext;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes tenant context for the request. Layer 1 of five.
 *
 * The single most important rule in this class: a tenant id arriving in a
 * request body, query string, JSON payload, header or hidden form field is
 * IGNORED. Tenant identity comes only from a re-validated membership record.
 *
 * See docs/03-TENANCY.md §3.
 */
final class ResolveTenant
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(401);
        }

        $sessionKey = (string) config('tenancy.resolution.session_key', 'tenant_id');
        $tenantId = $request->session()->get($sessionKey);

        $membership = $this->resolveMembership($user->getKey(), $tenantId);

        // No valid membership: either the session named a tenant the user was
        // removed from, or they belong to several and have not chosen one.
        if ($membership === null) {
            $request->session()->forget($sessionKey);

            abort(403, 'You do not have access to this workspace.');
        }

        // Re-stamp the session so a sole-membership resolution sticks.
        $request->session()->put($sessionKey, $membership->tenant_id);

        $this->context->set($membership->tenant);

        // Bind spatie's team context so role and permission lookups are
        // scoped to this tenant for the rest of the request.
        setPermissionsTeamId($membership->tenant_id);

        return $next($request);
    }

    /**
     * Membership is re-read from the database on every request -- never
     * trusted from the session alone, because access can be revoked mid-session.
     */
    private function resolveMembership(int $userId, mixed $tenantId): ?TenantUser
    {
        /** @var Builder<TenantUser> $query */
        $query = TenantUser::query()
            ->with('tenant')
            ->active()
            ->where('user_id', $userId);

        if (is_numeric($tenantId)) {
            return $query->where('tenant_id', (int) $tenantId)->first();
        }

        if (! in_array('sole_membership', (array) config('tenancy.resolution.strategies', []), true)) {
            return null;
        }

        // Only auto-select when there is exactly one candidate. Picking the
        // "first" of several would silently drop the user into an arbitrary
        // workspace.
        $memberships = $query->limit(2)->get();

        return $memberships->count() === 1 ? $memberships->first() : null;
    }
}
