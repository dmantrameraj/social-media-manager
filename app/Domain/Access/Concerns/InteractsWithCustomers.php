<?php

declare(strict_types=1);

namespace App\Domain\Access\Concerns;

use App\Domain\Customers\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Brand-level access, the second of the two dimensions that gate agency access.
 *
 * Authorization is the INTERSECTION of:
 *   1. does the user hold the permission?   (spatie, HasRoles)
 *   2. is the user assigned to this brand?  (this trait)
 *
 * Both must pass. A Content Creator with posts.create still cannot create a
 * post for a brand they are not assigned to. See docs/04-AUTH-RBAC.md §7.
 *
 * @phpstan-require-extends Model
 */
trait InteractsWithCustomers
{
    /** @var Collection<int, int>|null */
    private ?Collection $assignedCustomerIdCache = null;

    /**
     * Resolved once per request. Assignment is checked on essentially every
     * authorization decision, so an uncached lookup would put a query on every
     * policy call.
     *
     * @return Collection<int, int>
     */
    public function assignedCustomerIds(): Collection
    {
        return $this->assignedCustomerIdCache ??= $this->customers()
            ->pluck('customers.id')
            ->map(static fn (mixed $id): int => (int) $id);
    }

    /**
     * Users holding customers.view_all see every brand in the tenant; everyone
     * else sees only what they are assigned.
     *
     * Note this deliberately does NOT check the tenant: the caller is
     * responsible for having resolved a customer that already passed the
     * tenant scope. Policies check tenant, assignment and permission together.
     */
    public function canAccessCustomer(Customer|int|null $customer): bool
    {
        if ($customer === null) {
            return false;
        }

        if ($this->can('customers.view_all')) {
            return true;
        }

        $customerId = $customer instanceof Customer ? $customer->getKey() : $customer;

        return $this->assignedCustomerIds()->contains($customerId);
    }

    /**
     * Invalidate the per-request cache after an assignment change, so a
     * long-running job or a request that reassigns brands does not keep acting
     * on a stale set.
     */
    public function forgetAssignedCustomers(): void
    {
        $this->assignedCustomerIdCache = null;
    }
}
