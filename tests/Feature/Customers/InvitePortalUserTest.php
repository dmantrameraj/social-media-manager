<?php

declare(strict_types=1);

use App\Domain\Billing\Entitlements\EntitlementResolver;
use App\Domain\Billing\Entitlements\Exceptions\EntitlementExceeded;
use App\Domain\Customers\Enums\PortalRole;
use App\Domain\Customers\Models\Customer;
use App\Domain\Customers\Services\InvitePortalUserService;
use App\Domain\Identity\Models\CustomerPortalUser;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Services\ProvisionTenantService;

beforeEach(function (): void {
    seedPermissions();
    $this->service = app(InvitePortalUserService::class);

    $this->owner = User::factory()->create();
    $this->tenant = app(ProvisionTenantService::class)->execute($this->owner, 'Bright Digital');
    $this->owner = $this->owner->fresh();

    givePlanLimit($this->tenant->getKey(), 'portal_users.max', 10);
    app(EntitlementResolver::class)->forget($this->tenant);

    actingForTenant($this->tenant);
    $this->brand = Customer::factory()->create(['tenant_id' => $this->tenant->getKey()]);
});

it('creates a portal login bound to the tenant and brand', function (): void {
    ['user' => $portalUser] = $this->service->execute(
        $this->tenant, $this->owner, 'Client Name', 'client@example.com', [$this->brand->getKey()]
    );

    expect($portalUser->tenant_id)->toBe($this->tenant->getKey())
        ->and($portalUser->canAccessCustomer($this->brand))->toBeTrue()
        ->and($portalUser->canApproveFor($this->brand))->toBeTrue();
});

it('honours a viewer role that cannot approve', function (): void {
    ['user' => $portalUser] = $this->service->execute(
        $this->tenant, $this->owner, 'Viewer', 'viewer@example.com',
        [$this->brand->getKey()], PortalRole::Viewer
    );

    expect($portalUser->canAccessCustomer($this->brand))->toBeTrue()
        ->and($portalUser->canApproveFor($this->brand))->toBeFalse();
});

it('refuses to create a login with no brand', function (): void {
    // A portal login with no brand sees nothing, which reads as a broken
    // account rather than a restricted one.
    expect(fn () => $this->service->execute(
        $this->tenant, $this->owner, 'Nobody', 'nobody@example.com', []
    ))->toThrow(RuntimeException::class);
});

it('refuses a brand belonging to another tenant', function (): void {
    $otherTenant = app(ProvisionTenantService::class)
        ->execute(User::factory()->create(), 'Other Agency');

    withoutTenantContext();
    $foreignBrand = Customer::factory()->create(['tenant_id' => $otherTenant->getKey()]);
    actingForTenant($this->tenant);

    expect(fn () => $this->service->execute(
        $this->tenant, $this->owner, 'Client', 'client@example.com', [$foreignBrand->getKey()]
    ))->toThrow(RuntimeException::class);

    expect(CustomerPortalUser::query()->count())->toBe(0);
});

it('rejects a duplicate email within the same tenant', function (): void {
    $this->service->execute(
        $this->tenant, $this->owner, 'Client', 'client@example.com', [$this->brand->getKey()]
    );

    expect(fn () => $this->service->execute(
        $this->tenant, $this->owner, 'Client Again', 'client@example.com', [$this->brand->getKey()]
    ))->toThrow(RuntimeException::class);
});

it('allows the same email to hold a login in a different tenant', function (): void {
    $this->service->execute(
        $this->tenant, $this->owner, 'Client', 'shared@example.com', [$this->brand->getKey()]
    );

    $otherOwner = User::factory()->create();
    $other = app(ProvisionTenantService::class)->execute($otherOwner, 'Other Agency');
    givePlanLimit($other->getKey(), 'portal_users.max', 10);
    app(EntitlementResolver::class)->forget($other);

    actingForTenant($other);
    $otherBrand = Customer::factory()->create(['tenant_id' => $other->getKey()]);

    ['user' => $second] = $this->service->execute(
        $other, $otherOwner->fresh(), 'Client', 'shared@example.com', [$otherBrand->getKey()]
    );

    // The same person working with two agencies gets two logins, deliberately.
    expect($second->tenant_id)->toBe($other->getKey())
        ->and(CustomerPortalUser::query()->acrossTenants()
            ->where('email', 'shared@example.com')->count())->toBe(2);
});

it('enforces the portal user limit', function (): void {
    givePlanLimit($this->tenant->getKey(), 'portal_users.max', 1);
    app(EntitlementResolver::class)->forget($this->tenant);

    $this->service->execute(
        $this->tenant, $this->owner, 'First', 'first@example.com', [$this->brand->getKey()]
    );
    app(EntitlementResolver::class)->forget($this->tenant);

    expect(fn () => $this->service->execute(
        $this->tenant, $this->owner, 'Second', 'second@example.com', [$this->brand->getKey()]
    ))->toThrow(EntitlementExceeded::class);
});

it('does not leave a placeholder password that could be guessed', function (): void {
    ['user' => $portalUser, 'password' => $placeholder] = $this->service->execute(
        $this->tenant, $this->owner, 'Client', 'client@example.com', [$this->brand->getKey()]
    );

    // The placeholder is random and hashed; the real credential is set through
    // a single-use link in the invitation email.
    expect(strlen($placeholder))->toBeGreaterThanOrEqual(32)
        ->and($portalUser->password)->not->toBe($placeholder)
        ->and(password_verify($placeholder, $portalUser->password))->toBeTrue();
});
