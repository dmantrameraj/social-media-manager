<?php

declare(strict_types=1);

use App\Domain\Billing\Entitlements\EntitlementResolver;
use App\Domain\Billing\Entitlements\Exceptions\EntitlementExceeded;
use App\Domain\Customers\Enums\CustomerStatus;
use App\Domain\Customers\Models\Customer;
use App\Domain\Customers\Services\CreateCustomerService;
use App\Domain\Identity\Models\User;
use App\Domain\Media\Models\MediaFolder;
use App\Domain\Tenancy\Services\ProvisionTenantService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    seedPermissions();
    $this->service = app(CreateCustomerService::class);
    $this->owner = User::factory()->create();
    $this->tenant = app(ProvisionTenantService::class)->execute($this->owner, 'Bright Digital');
    $this->owner = $this->owner->fresh();
    actingForTenant($this->tenant);
});

it('creates a brand bound to the acting tenant', function (): void {
    givePlanLimit($this->tenant->getKey(), 'brands.max', 5);

    $customer = $this->service->execute($this->tenant, $this->owner, [
        'name' => 'ABC Restaurant',
    ]);

    expect($customer->tenant_id)->toBe($this->tenant->getKey())
        ->and($customer->status)->toBe(CustomerStatus::Active)
        ->and($customer->slug)->toBe('abc-restaurant');
});

it('inherits the agency timezone when none is given', function (): void {
    givePlanLimit($this->tenant->getKey(), 'brands.max', 5);

    $customer = $this->service->execute($this->tenant, $this->owner, ['name' => 'ABC']);

    expect($customer->timezone)->toBe($this->tenant->timezone);
});

it('assigns the creator so they do not lose access to what they just made', function (): void {
    givePlanLimit($this->tenant->getKey(), 'brands.max', 5);

    $customer = $this->service->execute($this->tenant, $this->owner, ['name' => 'ABC']);

    expect($customer->users()->pluck('users.id'))->toContain($this->owner->getKey());
});

it('seeds the system media folders', function (): void {
    givePlanLimit($this->tenant->getKey(), 'brands.max', 5);

    $customer = $this->service->execute($this->tenant, $this->owner, ['name' => 'ABC']);

    $folders = MediaFolder::query()->where('customer_id', $customer->getKey())->pluck('system_key');

    expect($folders)->toContain('logos', 'products', 'reels')
        ->and($folders)->toHaveCount(count(config('media.system_folders')));
});

it('makes slugs unique within the tenant', function (): void {
    givePlanLimit($this->tenant->getKey(), 'brands.max', 5);

    $first = $this->service->execute($this->tenant, $this->owner, ['name' => 'Same Brand']);
    $second = $this->service->execute($this->tenant, $this->owner, ['name' => 'Same Brand']);

    expect($first->slug)->not->toBe($second->slug);
});

it('allows the same slug in a different tenant', function (): void {
    givePlanLimit($this->tenant->getKey(), 'brands.max', 5);
    $this->service->execute($this->tenant, $this->owner, ['name' => 'Shared Name']);

    $otherOwner = User::factory()->create();
    $other = app(ProvisionTenantService::class)->execute($otherOwner, 'Other Agency');
    givePlanLimit($other->getKey(), 'brands.max', 5);
    actingForTenant($other);

    $customer = $this->service->execute($other, $otherOwner->fresh(), ['name' => 'Shared Name']);

    // Slugs are unique per tenant, not globally -- a global unique key would
    // leak the existence of other agencies' brands.
    expect($customer->slug)->toBe('shared-name');
});

it('enforces the brand limit at the service layer', function (): void {
    givePlanLimit($this->tenant->getKey(), 'brands.max', 2);

    $this->service->execute($this->tenant, $this->owner, ['name' => 'One']);
    $this->service->execute($this->tenant, $this->owner, ['name' => 'Two']);

    expect(fn () => $this->service->execute($this->tenant, $this->owner, ['name' => 'Three']))
        ->toThrow(EntitlementExceeded::class);

    expect(Customer::query()->count())->toBe(2);
});

it('lets an override raise the limit with no plan change', function (): void {
    givePlanLimit($this->tenant->getKey(), 'brands.max', 1);
    $this->service->execute($this->tenant, $this->owner, ['name' => 'One']);

    DB::table('subscription_overrides')->insert([
        'tenant_id' => $this->tenant->getKey(),
        'key' => 'brands.max',
        'value_type' => 'limit',
        'value' => 10,
        'reason' => 'Negotiated',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    app(EntitlementResolver::class)->forget($this->tenant);

    $second = $this->service->execute($this->tenant, $this->owner, ['name' => 'Two']);

    expect($second->exists)->toBeTrue()
        ->and(Customer::query()->count())->toBe(2);
});

it('ignores a tenant_id injected into the attributes', function (): void {
    givePlanLimit($this->tenant->getKey(), 'brands.max', 5);

    $otherTenant = app(ProvisionTenantService::class)
        ->execute(User::factory()->create(), 'Other Agency');

    $customer = $this->service->execute($this->tenant, $this->owner, [
        'name' => 'ABC',
        'tenant_id' => $otherTenant->getKey(),
    ]);

    expect($customer->tenant_id)->toBe($this->tenant->getKey());
});

it('rolls back completely if folder seeding fails', function (): void {
    givePlanLimit($this->tenant->getKey(), 'brands.max', 5);

    // A folder name longer than the column allows aborts mid-transaction.
    config()->set('media.system_folders', ['bad' => str_repeat('x', 500)]);

    expect(fn () => $this->service->execute($this->tenant, $this->owner, ['name' => 'ABC']))
        ->toThrow(QueryException::class);

    expect(Customer::query()->count())->toBe(0);
});
