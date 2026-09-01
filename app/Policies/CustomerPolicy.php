<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Access\Policies\TenantScopedPolicy;
use App\Domain\Customers\Enums\CustomerStatus;
use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

final class CustomerPolicy extends TenantScopedPolicy
{
    protected function permissionPrefix(): string
    {
        return 'customers';
    }

    /**
     * A customer IS the brand, so brand access is checked against its own key
     * rather than a customer_id column it does not have.
     */
    protected function customerIdFor(Model $model): ?int
    {
        return $model->getKey();
    }

    public function archive(User $user, Customer $customer): bool
    {
        return $this->canReach($user, $customer)
            && $user->can('customers.archive')
            && $customer->status === CustomerStatus::Active;
    }

    public function unarchive(User $user, Customer $customer): bool
    {
        return $this->canReach($user, $customer)
            && $user->can('customers.archive')
            && $customer->status === CustomerStatus::Archived;
    }

    /**
     * Deleting a brand destroys its content, media and connections, so it is
     * gated on the delete permission AND on the brand already being archived.
     * Archiving first is a deliberate speed bump on an irreversible action.
     */
    public function delete(User $user, Model $model): bool
    {
        return parent::delete($user, $model)
            && $model->getAttribute('status') === CustomerStatus::Archived;
    }
}
