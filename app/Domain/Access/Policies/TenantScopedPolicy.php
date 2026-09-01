<?php

declare(strict_types=1);

namespace App\Domain\Access\Policies;

use App\Domain\Identity\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Base for every tenant-owned model's policy.
 *
 * Encodes the three-part check described in docs/03-TENANCY.md §5 once, so no
 * individual policy can forget a leg of it:
 *
 *   1. the record belongs to the tenant currently in context
 *   2. the user is assigned to the record's brand (or holds customers.view_all)
 *   3. the user holds the permission
 *
 * Global scopes already stop accidental cross-tenant reads. This layer stops
 * deliberate ones -- the cases where an id arrives in a payload and the scope
 * never sees it.
 */
abstract class TenantScopedPolicy
{
    /**
     * The permission prefix for this resource, e.g. 'customers' or 'posts'.
     */
    abstract protected function permissionPrefix(): string;

    /**
     * The brand a record belongs to. Null for records that are not
     * brand-scoped (the tenant itself, team members).
     */
    protected function customerIdFor(Model $model): ?int
    {
        return $model->getAttribute('customer_id');
    }

    /**
     * Legs 1 and 2. Deliberately separate from the permission check so the
     * concrete policy can compose them with workflow state.
     */
    protected function canReach(User $user, Model $model): bool
    {
        $context = app(TenantContext::class);

        if (! $context->hasTenant()) {
            return false;
        }

        if ($model->getAttribute('tenant_id') !== $context->id()) {
            return false;
        }

        $customerId = $this->customerIdFor($model);

        // Not brand-scoped: the tenant check above is sufficient.
        if ($customerId === null) {
            return true;
        }

        return $user->canAccessCustomer($customerId);
    }

    protected function allows(User $user, Model $model, string $ability): bool
    {
        return $this->canReach($user, $model)
            && $user->can($this->permissionPrefix().'.'.$ability);
    }

    // ------------------------------------------------------------- defaults

    public function viewAny(User $user): bool
    {
        return $user->can($this->permissionPrefix().'.view');
    }

    public function view(User $user, Model $model): bool
    {
        return $this->allows($user, $model, 'view');
    }

    /**
     * Creation has no model to check, so brand access is verified by the
     * calling service against the submitted customer_id. A policy cannot do
     * it here, and pretending otherwise would be a false sense of safety.
     */
    public function create(User $user): bool
    {
        return $user->can($this->permissionPrefix().'.create');
    }

    public function update(User $user, Model $model): bool
    {
        return $this->allows($user, $model, 'update');
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->allows($user, $model, 'delete');
    }

    public function restore(User $user, Model $model): bool
    {
        return $this->allows($user, $model, 'delete');
    }
}
