<?php

declare(strict_types=1);

use App\Domain\Customers\Enums\CustomerStatus;
use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Media\Models\Media;
use App\Domain\Media\Models\MediaFolder;
use App\Domain\Tenancy\Enums\MembershipStatus;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Services\ProvisionTenantService;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    seedPermissions();
    $this->provision = app(ProvisionTenantService::class);
});

/** Create a member of $tenant holding $role. */
function memberWithRole(Tenant $tenant, string $role): User
{
    $user = User::factory()->create();

    $user->tenants()->attach($tenant->getKey(), [
        'status' => MembershipStatus::Active->value,
        'joined_at' => now(),
    ]);

    $registrar = app(PermissionRegistrar::class);
    $previous = $registrar->getPermissionsTeamId();
    $registrar->setPermissionsTeamId($tenant->getKey());

    try {
        $user->assignRole($role);
    } finally {
        $registrar->setPermissionsTeamId($previous);
    }

    return $user->fresh();
}

// ------------------------------------------------------- tenant boundary (leg 1)

it('denies access to a brand in another tenant even with the permission', function (): void {
    $tenantA = $this->provision->execute(User::factory()->create(), 'Agency A');
    $tenantB = $this->provision->execute(User::factory()->create(), 'Agency B');

    $managerA = memberWithRole($tenantA, 'Manager');

    withoutTenantContext();
    $brandB = Customer::factory()->create(['tenant_id' => $tenantB->getKey()]);

    actingForTenant($tenantA);

    // Holds customers.view_all inside their own tenant, but the record is not
    // theirs, so the tenant leg fails first.
    expect($managerA->can('view', $brandB))->toBeFalse()
        ->and($managerA->can('update', $brandB))->toBeFalse()
        ->and($managerA->can('delete', $brandB))->toBeFalse();
});

// -------------------------------------------------- brand assignment (leg 2)

it('denies an unassigned brand within the same tenant', function (): void {
    $tenant = $this->provision->execute(User::factory()->create(), 'Agency A');

    // Content Creator has posts/media permissions but NOT customers.view_all,
    // so their reach is limited to assigned brands.
    $creator = memberWithRole($tenant, 'Content Creator');

    actingForTenant($tenant);
    $assigned = Customer::factory()->create(['tenant_id' => $tenant->getKey()]);
    $unassigned = Customer::factory()->create(['tenant_id' => $tenant->getKey()]);

    $assigned->users()->attach($creator->getKey());
    $creator->forgetAssignedCustomers();

    expect($creator->can('view', $assigned))->toBeTrue()
        ->and($creator->can('view', $unassigned))->toBeFalse();
});

it('lets a holder of customers.view_all reach every brand in their tenant', function (): void {
    $tenant = $this->provision->execute(User::factory()->create(), 'Agency A');
    $manager = memberWithRole($tenant, 'Manager');

    actingForTenant($tenant);
    $brand = Customer::factory()->create(['tenant_id' => $tenant->getKey()]);

    expect($manager->can('view', $brand))->toBeTrue();
});

// ------------------------------------------------------------ permission (leg 3)

it('denies an action the role does not carry, even on an assigned brand', function (): void {
    $tenant = $this->provision->execute(User::factory()->create(), 'Agency A');
    $designer = memberWithRole($tenant, 'Designer');

    actingForTenant($tenant);
    $brand = Customer::factory()->create(['tenant_id' => $tenant->getKey()]);
    $brand->users()->attach($designer->getKey());
    $designer->forgetAssignedCustomers();

    // Designer holds customers.view but not customers.update or delete.
    expect($designer->can('view', $brand))->toBeTrue()
        ->and($designer->can('update', $brand))->toBeFalse()
        ->and($designer->can('delete', $brand))->toBeFalse();
});

// ------------------------------------------------------------- workflow gating

it('requires a brand to be archived before it can be deleted', function (): void {
    $tenant = $this->provision->execute(User::factory()->create(), 'Agency A');
    $owner = memberWithRole($tenant, 'Agency Owner');

    actingForTenant($tenant);
    $brand = Customer::factory()->create([
        'tenant_id' => $tenant->getKey(),
        'status' => CustomerStatus::Active,
    ]);

    // Deleting a brand destroys its content; archiving first is a deliberate
    // speed bump on an irreversible action.
    expect($owner->can('delete', $brand))->toBeFalse();

    $brand->status = CustomerStatus::Archived;
    $brand->save();

    expect($owner->can('delete', $brand->fresh()))->toBeTrue();
});

it('refuses to rename or delete a seeded system folder', function (): void {
    $tenant = $this->provision->execute(User::factory()->create(), 'Agency A');
    $owner = memberWithRole($tenant, 'Agency Owner');

    actingForTenant($tenant);
    $brand = Customer::factory()->create(['tenant_id' => $tenant->getKey()]);

    $system = MediaFolder::factory()->forCustomer($brand)->system('logos')->create();
    $normal = MediaFolder::factory()->forCustomer($brand)->create();

    expect($owner->can('update', $system))->toBeFalse()
        ->and($owner->can('delete', $system))->toBeFalse()
        ->and($owner->can('update', $normal))->toBeTrue();
});

it('gates media download behind brand access', function (): void {
    $tenantA = $this->provision->execute(User::factory()->create(), 'Agency A');
    $tenantB = $this->provision->execute(User::factory()->create(), 'Agency B');
    $managerA = memberWithRole($tenantA, 'Manager');

    withoutTenantContext();
    $brandB = Customer::factory()->create(['tenant_id' => $tenantB->getKey()]);
    $mediaB = Media::factory()->forCustomer($brandB)->create();

    actingForTenant($tenantA);

    expect($managerA->can('download', $mediaB))->toBeFalse();
});

it('denies everything when there is no tenant context', function (): void {
    $tenant = $this->provision->execute(User::factory()->create(), 'Agency A');
    $owner = memberWithRole($tenant, 'Agency Owner');

    actingForTenant($tenant);
    $brand = Customer::factory()->create(['tenant_id' => $tenant->getKey()]);

    withoutTenantContext();

    expect($owner->can('view', $brand))->toBeFalse()
        ->and($owner->can('update', $brand))->toBeFalse();
});
