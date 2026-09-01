<?php

declare(strict_types=1);

use App\Domain\Billing\Entitlements\EntitlementResolver;
use App\Domain\Billing\Entitlements\Exceptions\EntitlementExceeded;
use App\Domain\Customers\Enums\CustomerStatus;
use App\Domain\Customers\Models\Customer;
use App\Domain\Customers\Services\CreateCustomerService;
use App\Domain\Customers\Services\UpdateCustomerService;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Services\ProvisionTenantService;

beforeEach(function (): void {
    seedPermissions();
    $this->create = app(CreateCustomerService::class);
    $this->update = app(UpdateCustomerService::class);

    $this->owner = User::factory()->create();
    $this->tenant = app(ProvisionTenantService::class)->execute($this->owner, 'Bright Digital');
    $this->owner = $this->owner->fresh();

    actingForTenant($this->tenant);
});

it('updates editable fields', function (): void {
    givePlanLimit($this->tenant->getKey(), 'brands.max', 5);
    app(EntitlementResolver::class)->forget($this->tenant);

    $customer = $this->create->execute($this->tenant, $this->owner, ['name' => 'Before']);

    $this->update->execute($customer, [
        'name' => 'After',
        'industry' => 'Hospitality',
        'contact_email' => 'ops@example.com',
    ]);

    $customer->refresh();

    expect($customer->name)->toBe('After')
        ->and($customer->industry)->toBe('Hospitality');
});

it('ignores attempts to change ownership or slug through update', function (): void {
    givePlanLimit($this->tenant->getKey(), 'brands.max', 5);
    app(EntitlementResolver::class)->forget($this->tenant);

    $customer = $this->create->execute($this->tenant, $this->owner, ['name' => 'Brand']);
    $originalSlug = $customer->slug;

    $other = app(ProvisionTenantService::class)
        ->execute(User::factory()->create(), 'Other Agency');

    $this->update->execute($customer, [
        'name' => 'Renamed',
        'slug' => 'hijacked-slug',
        'tenant_id' => $other->getKey(),
        'status' => CustomerStatus::Archived->value,
    ]);

    $customer->refresh();

    // Slug, tenant and status are lifecycle-owned and unreachable from input.
    expect($customer->name)->toBe('Renamed')
        ->and($customer->slug)->toBe($originalSlug)
        ->and($customer->tenant_id)->toBe($this->tenant->getKey())
        ->and($customer->status)->toBe(CustomerStatus::Active);
});

it('frees a brand slot when archived', function (): void {
    givePlanLimit($this->tenant->getKey(), 'brands.max', 2);
    app(EntitlementResolver::class)->forget($this->tenant);

    $first = $this->create->execute($this->tenant, $this->owner, ['name' => 'One']);
    $this->create->execute($this->tenant, $this->owner, ['name' => 'Two']);

    // At the limit.
    expect(fn () => $this->create->execute($this->tenant, $this->owner, ['name' => 'Three']))
        ->toThrow(EntitlementExceeded::class);

    $this->update->archive($first);

    // Archiving frees the slot, so a replacement brand is now allowed.
    $third = $this->create->execute($this->tenant, $this->owner, ['name' => 'Three']);

    expect($third->exists)->toBeTrue()
        ->and($first->fresh()->status)->toBe(CustomerStatus::Archived);
});

it('re-checks the limit when unarchiving', function (): void {
    givePlanLimit($this->tenant->getKey(), 'brands.max', 2);
    app(EntitlementResolver::class)->forget($this->tenant);

    $archived = $this->create->execute($this->tenant, $this->owner, ['name' => 'Old Client']);
    $this->update->archive($archived);

    $this->create->execute($this->tenant, $this->owner, ['name' => 'New One']);
    $this->create->execute($this->tenant, $this->owner, ['name' => 'New Two']);

    // Two active brands already fill the plan, so restoring a third must fail
    // -- an agency that downgraded while a brand slept does not get it free.
    expect(fn () => $this->update->unarchive($archived))
        ->toThrow(EntitlementExceeded::class);

    expect($archived->fresh()->status)->toBe(CustomerStatus::Archived);
});

it('restores an archived brand when there is room', function (): void {
    givePlanLimit($this->tenant->getKey(), 'brands.max', 5);
    app(EntitlementResolver::class)->forget($this->tenant);

    $customer = $this->create->execute($this->tenant, $this->owner, ['name' => 'Client']);
    $this->update->archive($customer);

    $this->update->unarchive($customer);

    expect($customer->fresh()->status)->toBe(CustomerStatus::Active);
});

it('keeps archived brands out of the active scope but still readable', function (): void {
    givePlanLimit($this->tenant->getKey(), 'brands.max', 5);
    app(EntitlementResolver::class)->forget($this->tenant);

    $customer = $this->create->execute($this->tenant, $this->owner, ['name' => 'Client']);
    $this->update->archive($customer);

    // Archived is not deleted: the agency keeps the history.
    expect(Customer::query()->active()->count())->toBe(0)
        ->and(Customer::query()->count())->toBe(1);
});
