<?php

declare(strict_types=1);

use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\CustomerPortalUser;
use App\Domain\Media\Models\Media;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Tenancy\Exceptions\MissingTenantException;
use App\Domain\Tenancy\Exceptions\TenantReassignmentException;
use App\Domain\Tenancy\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Tenant isolation -- MERGE GATE
|--------------------------------------------------------------------------
|
| These tests are non-negotiable. A failure here means one agency can reach
| another agency's data, which is the single failure mode that would end this
| product. There is no override path: if this file is red, nothing ships.
|
| Covers docs/03-TENANCY.md §7 cases 1-5 and 10. Route-level cases (6-9)
| arrive with the HTTP surface in Steps 4-6.
|
*/

beforeEach(function (): void {
    $this->tenantA = Tenant::factory()->create(['name' => 'Agency A']);
    $this->tenantB = Tenant::factory()->create(['name' => 'Agency B']);
});

// -------------------------------------------------------------- 1. read isolation

it('does not leak records from another tenant, for every tenant-owned model', function (string $modelClass): void {
    // Seed one record in each tenant.
    withoutTenantContext();
    $ownA = $modelClass::factory()->create(['tenant_id' => $this->tenantA->getKey()]);
    $ownB = $modelClass::factory()->create(['tenant_id' => $this->tenantB->getKey()]);

    actingForTenant($this->tenantA);

    $visible = $modelClass::query()->pluck('id');

    expect($visible)->toContain($ownA->getKey())
        ->and($visible)->not->toContain($ownB->getKey());

    // Direct lookup by a foreign primary key must also miss.
    expect($modelClass::query()->find($ownB->getKey()))->toBeNull();
})->with(tenantOwnedModels());

it('scopes counts and aggregates to the active tenant', function (): void {
    withoutTenantContext();
    Customer::factory()->count(3)->create(['tenant_id' => $this->tenantA->getKey()]);
    Customer::factory()->count(5)->create(['tenant_id' => $this->tenantB->getKey()]);

    actingForTenant($this->tenantA);
    expect(Customer::query()->count())->toBe(3);

    actingForTenant($this->tenantB);
    expect(Customer::query()->count())->toBe(5);
});

// ------------------------------------------------------------- 2. write isolation

it('cannot update another tenant record through a scoped query', function (): void {
    withoutTenantContext();
    $foreign = Customer::factory()->create([
        'tenant_id' => $this->tenantB->getKey(),
        'name' => 'Untouched',
    ]);

    actingForTenant($this->tenantA);

    $affected = Customer::query()->whereKey($foreign->getKey())->update(['name' => 'Hijacked']);

    expect($affected)->toBe(0)
        ->and($foreign->fresh()->name)->toBe('Untouched');
});

it('cannot delete another tenant record through a scoped query', function (): void {
    withoutTenantContext();
    $foreign = Customer::factory()->create(['tenant_id' => $this->tenantB->getKey()]);

    actingForTenant($this->tenantA);

    expect(Customer::query()->whereKey($foreign->getKey())->delete())->toBe(0);

    withoutTenantContext();
    expect(Customer::query()->find($foreign->getKey()))->not->toBeNull();
});

// --------------------------------------------------------------- 3. create binding

it('stamps the active tenant on create', function (): void {
    actingForTenant($this->tenantA);

    $customer = Customer::query()->create([
        'name' => 'Brand X',
        'slug' => 'brand-x',
    ]);

    expect($customer->tenant_id)->toBe($this->tenantA->getKey());
});

/*
 | An injected tenant_id is refused in BOTH modes, but differently, so both
 | are asserted:
 |
 |   development/test -- Model::preventSilentlyDiscardingAttributes(true)
 |                       makes it THROW, surfacing the bug immediately.
 |   production       -- the guard silently discards it and context wins,
 |                       so a malicious payload degrades to a no-op rather
 |                       than a 500.
 |
 | The security property is identical either way: the record is never created
 | under the attacker's tenant.
 */

it('throws in development when a tenant_id is injected through mass assignment', function (): void {
    actingForTenant($this->tenantA);

    // The attack: a hidden form field naming another tenant.
    expect(fn () => Customer::query()->create([
        'name' => 'Brand Y',
        'slug' => 'brand-y',
        'tenant_id' => $this->tenantB->getKey(),
    ]))->toThrow(MassAssignmentException::class);
});

it('silently ignores an injected tenant_id under production semantics', function (): void {
    Model::preventSilentlyDiscardingAttributes(false);

    try {
        actingForTenant($this->tenantA);

        $customer = Customer::query()->create([
            'name' => 'Brand Y',
            'slug' => 'brand-y',
            'tenant_id' => $this->tenantB->getKey(),
        ]);

        expect($customer->tenant_id)->toBe($this->tenantA->getKey())
            ->and($customer->tenant_id)->not->toBe($this->tenantB->getKey());
    } finally {
        Model::preventSilentlyDiscardingAttributes(true);
    }
});

it('ignores an injected tenant_id through fill under production semantics', function (): void {
    Model::preventSilentlyDiscardingAttributes(false);

    try {
        actingForTenant($this->tenantA);

        $customer = new Customer;
        $customer->fill([
            'name' => 'Brand Z',
            'slug' => 'brand-z',
            'tenant_id' => $this->tenantB->getKey(),
        ]);
        $customer->save();

        expect($customer->tenant_id)->toBe($this->tenantA->getKey());
    } finally {
        Model::preventSilentlyDiscardingAttributes(true);
    }
});

it('keeps tenant_id out of the fillable set on every tenant-owned model', function (string $modelClass): void {
    $model = new $modelClass;

    expect($model->isFillable('tenant_id'))->toBeFalse(
        "[{$modelClass}] allows tenant_id to be mass assigned."
    );
})->with(tenantOwnedModels());

// ---------------------------------------------------------------- 4. reassignment

it('refuses to move a record between tenants', function (): void {
    actingForTenant($this->tenantA);
    $customer = Customer::query()->create(['name' => 'Brand', 'slug' => 'brand-move']);

    $customer->tenant_id = $this->tenantB->getKey();

    expect(fn () => $customer->save())->toThrow(TenantReassignmentException::class);
});

// -------------------------------------------------------------- 5. missing context

it('refuses to create a tenant-owned record with no tenant context', function (): void {
    withoutTenantContext();

    expect(fn () => Customer::query()->create(['name' => 'Orphan', 'slug' => 'orphan']))
        ->toThrow(MissingTenantException::class);
});

it('allows an explicit tenant_id with no context, for jobs and seeders', function (): void {
    withoutTenantContext();

    $customer = Customer::factory()->create(['tenant_id' => $this->tenantB->getKey()]);

    expect($customer->tenant_id)->toBe($this->tenantB->getKey());
});

// ------------------------------------------------------- deliberate scope bypasses

it('exposes all tenants only through the explicit acrossTenants bypass', function (): void {
    withoutTenantContext();
    Customer::factory()->create(['tenant_id' => $this->tenantA->getKey()]);
    Customer::factory()->create(['tenant_id' => $this->tenantB->getKey()]);

    actingForTenant($this->tenantA);

    expect(Customer::query()->count())->toBe(1)
        ->and(Customer::query()->acrossTenants()->count())->toBe(2);
});

it('restores the previous context after a scoped run', function (): void {
    $context = app(TenantContext::class);
    actingForTenant($this->tenantA);

    $context->run($this->tenantB, function () use ($context): void {
        expect($context->id())->toBe($this->tenantB->getKey());
    });

    expect($context->id())->toBe($this->tenantA->getKey());
});

it('restores context even when the callback throws', function (): void {
    $context = app(TenantContext::class);
    actingForTenant($this->tenantA);

    try {
        $context->run($this->tenantB, function (): void {
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect($context->id())->toBe($this->tenantA->getKey());
});

// ------------------------------------------------ related-record isolation

it('does not leak media belonging to another tenant customer', function (): void {
    withoutTenantContext();
    $customerA = Customer::factory()->create(['tenant_id' => $this->tenantA->getKey()]);
    $customerB = Customer::factory()->create(['tenant_id' => $this->tenantB->getKey()]);

    Media::factory()->forCustomer($customerA)->create();
    $foreignMedia = Media::factory()->forCustomer($customerB)->create();

    actingForTenant($this->tenantA);

    expect(Media::query()->count())->toBe(1)
        ->and(Media::query()->find($foreignMedia->getKey()))->toBeNull();
});

it('isolates portal users between tenants', function (): void {
    withoutTenantContext();
    CustomerPortalUser::factory()->create(['tenant_id' => $this->tenantA->getKey()]);
    $foreign = CustomerPortalUser::factory()->create(['tenant_id' => $this->tenantB->getKey()]);

    actingForTenant($this->tenantA);

    expect(CustomerPortalUser::query()->count())->toBe(1)
        ->and(CustomerPortalUser::query()->find($foreign->getKey()))->toBeNull();
});

it('allows the same portal email in two different tenants', function (): void {
    withoutTenantContext();

    $email = 'client@example.com';
    CustomerPortalUser::factory()->create(['tenant_id' => $this->tenantA->getKey(), 'email' => $email]);
    CustomerPortalUser::factory()->create(['tenant_id' => $this->tenantB->getKey(), 'email' => $email]);

    expect(CustomerPortalUser::query()->acrossTenants()->where('email', $email)->count())->toBe(2);
});

// ------------------------------------------------------ 10. registry completeness

it('applies BelongsToTenant to every model with a tenant_id column', function (): void {
    $registered = tenantOwnedModels();

    foreach ($registered as $modelClass) {
        $model = new $modelClass;

        expect(Schema::hasColumn($model->getTable(), 'tenant_id'))
            ->toBeTrue("[{$modelClass}] is registered as tenant-owned but its table has no tenant_id column.");

        expect(in_array(BelongsToTenant::class, class_uses_recursive($modelClass), true))
            ->toBeTrue("[{$modelClass}] has a tenant_id column but does not use BelongsToTenant.");
    }
});

it('keeps the tenant-owned model registry in sync with the schema', function (): void {
    // Every table with a tenant_id column must be represented by a registered
    // model, or be on the explicit exemption list below. This is the test that
    // stops the suite rotting as the schema grows.
    $exempt = [
        // Join tables and platform-owned records: no Eloquent model of their
        // own, or deliberately unscoped.
        'tenant_user', 'customer_user', 'customer_portal_user_customer',
        'invitations', 'invoice_lines', 'coupon_redemptions',
        'subscriptions', 'subscription_overrides', 'invoices', 'payments',
        'ai_credit_accounts', 'ai_credit_transactions',
        'audit_logs', 'login_histories', 'impersonation_sessions',
        'feature_flag_tenant', 'domains', 'branding_settings',
        'notification_preferences',

        // Child rows reached only through an already-scoped parent, plus
        // append-only logs and short-lived OAuth state. None is queried
        // directly by a user-facing path.
        'post_versions', 'post_media', 'post_approvals', 'post_comments',
        'publication_attempts', 'oauth_states',

        // Credentials are read only by the OAuth layer, never listed to users.
        'social_app_credentials',
    ];

    $registeredTables = array_map(
        static fn (string $class): string => (new $class)->getTable(),
        tenantOwnedModels(),
    );

    $tablesWithTenantId = collect(Schema::getTableListing(schema: null, schemaQualified: false))
        ->filter(static fn (string $table): bool => Schema::hasColumn($table, 'tenant_id'))
        ->reject(static fn (string $table): bool => in_array($table, $exempt, true))
        ->values();

    $unregistered = $tablesWithTenantId
        ->reject(static fn (string $table): bool => in_array($table, $registeredTables, true))
        ->all();

    expect($unregistered)->toBeEmpty(
        'These tables have a tenant_id column but no registered tenant-owned model: '
        .implode(', ', $unregistered)
        .'. Add the model to tenantOwnedModels() in tests/Pest.php, or add the table to the exemption list with a reason.'
    );
});
