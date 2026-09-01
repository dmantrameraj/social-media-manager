<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Access\Policies\TenantScopedPolicy;
use App\Domain\Identity\Models\CustomerPortalUser;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Governs AGENCY users managing client logins.
 *
 * Portal users' own access to their data is a separate question, answered on
 * the `customer` guard by brand assignment -- see CustomerPortalUser and
 * docs/04-AUTH-RBAC.md §8.
 */
final class CustomerPortalUserPolicy extends TenantScopedPolicy
{
    protected function permissionPrefix(): string
    {
        return 'portal_users';
    }

    /**
     * Portal users are tenant-scoped but not themselves brand-scoped: they can
     * hold access to several brands through a pivot. The tenant check is the
     * boundary here.
     */
    protected function customerIdFor(Model $model): ?int
    {
        return null;
    }

    public function create(User $user): bool
    {
        return $user->can('portal_users.invite');
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->canReach($user, $model)
            && $user->can('portal_users.remove');
    }

    /**
     * Assigning a portal user to a brand requires access to THAT brand, which
     * the calling service checks against the submitted customer_id -- a policy
     * receiving only the portal user cannot see which brand is being granted.
     */
    public function assign(User $user, CustomerPortalUser $portalUser): bool
    {
        return $this->canReach($user, $portalUser)
            && $user->can('portal_users.invite');
    }
}
