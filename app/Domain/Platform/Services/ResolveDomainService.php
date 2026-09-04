<?php

declare(strict_types=1);

namespace App\Domain\Platform\Services;

use App\Domain\Platform\Models\Domain;
use App\Domain\Tenancy\Models\Tenant;

/**
 * Turns a hostname into the agency it belongs to.
 *
 * Lives in App\Domain\Platform because this lookup is cross-tenant BY
 * DEFINITION -- a request arrives before any tenant is known, and finding out
 * which one it is, is the whole job. That namespace is already on
 * config('tenancy.scope_bypass_namespaces') for exactly this class of work,
 * so the bypass stays where its reasoning is rather than being granted to
 * every middleware in the application.
 */
final class ResolveDomainService
{
    /**
     * The tenant a hostname resolves to, or null.
     *
     * VERIFIED domains only. An unverified row is a claim, and resolving on a
     * claim would let anybody point DNS at this application and be served
     * another agency's portal.
     */
    public function tenantFor(string $hostname): ?Tenant
    {
        $domain = Domain::query()
            ->acrossTenants()
            ->resolvable()
            ->where('hostname', $hostname)
            ->first();

        if ($domain === null) {
            return null;
        }

        return Tenant::query()->find($domain->tenant_id);
    }
}
