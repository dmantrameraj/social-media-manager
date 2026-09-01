<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Tenancy\Exceptions\TenantNotResolved;
use App\Domain\Tenancy\Models\Tenant;
use Closure;

/**
 * Holds the active tenant for the current request, job or command.
 *
 * Registered as a SCOPED singleton: one instance per request/job, never shared
 * across them. This is what keeps it safe under Octane, where a plain singleton
 * would leak one tenant's context into the next request on the same worker.
 *
 * Context is established in exactly one place per entry point:
 *   - HTTP:    the ResolveTenant middleware
 *   - Jobs:    explicitly in handle(), from a tenant id on the job payload
 *   - Console: explicitly, per tenant, while iterating
 *
 * See docs/03-TENANCY.md §3.
 */
final class TenantContext
{
    private ?Tenant $tenant = null;

    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function forget(): void
    {
        $this->tenant = null;
    }

    public function hasTenant(): bool
    {
        return $this->tenant !== null;
    }

    /**
     * @throws TenantNotResolved when no tenant is active.
     */
    public function get(): Tenant
    {
        return $this->tenant ?? throw new TenantNotResolved;
    }

    /**
     * @throws TenantNotResolved when no tenant is active.
     */
    public function id(): int
    {
        return $this->get()->getKey();
    }

    /**
     * The tenant id, or null when unresolved.
     *
     * Use this only where the absence of a tenant is genuinely valid -- audit
     * logging of platform actions, for example. Anywhere a tenant is required,
     * call id() and let it throw.
     */
    public function idOrNull(): ?int
    {
        return $this->tenant?->getKey();
    }

    /**
     * Run a callback with a specific tenant active, restoring the previous
     * context afterwards even if the callback throws.
     *
     * Used by console commands iterating tenants and by Super Admin services
     * that need to act inside one tenant briefly.
     *
     * @template TReturn
     *
     * @param  Closure(Tenant): TReturn  $callback
     * @return TReturn
     */
    public function run(Tenant $tenant, Closure $callback): mixed
    {
        $previous = $this->tenant;

        $this->tenant = $tenant;

        try {
            return $callback($tenant);
        } finally {
            $this->tenant = $previous;
        }
    }

    /**
     * Run a callback with no tenant active.
     *
     * Note this does NOT bypass the tenant scope -- it removes the filter
     * entirely, which is only correct in genuinely cross-tenant contexts.
     * Prefer Model::acrossTenants() at the query level, which is greppable.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function runWithoutTenant(Closure $callback): mixed
    {
        $previous = $this->tenant;

        $this->tenant = null;

        try {
            return $callback();
        } finally {
            $this->tenant = $previous;
        }
    }
}
