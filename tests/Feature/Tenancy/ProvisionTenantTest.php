<?php

declare(strict_types=1);

use App\Domain\AI\Models\AiCreditAccount;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Enums\MembershipStatus;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Exceptions\MissingOwnerRoleException;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Services\ProvisionTenantService;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    seedPermissions();
    $this->service = app(ProvisionTenantService::class);
});

it('creates a tenant on trial with the configured trial length', function (): void {
    $owner = User::factory()->create();

    $tenant = $this->service->execute($owner, 'Bright Digital');

    expect($tenant->status)->toBe(TenantStatus::Trialing)
        ->and($tenant->trial_ends_at)->not->toBeNull()
        ->and($tenant->trial_ends_at->diffInDays(now()))
        ->toBeLessThanOrEqual((int) config('tenancy.trial_days'));
});

it('makes the creating user an active member and the owner', function (): void {
    $owner = User::factory()->create();

    $tenant = $this->service->execute($owner, 'Bright Digital');

    expect($tenant->owner_user_id)->toBe($owner->getKey())
        ->and($owner->fresh()->belongsToTenant($tenant))->toBeTrue();

    $membership = $tenant->memberships()->first();
    expect($membership->status)->toBe(MembershipStatus::Active);
});

it('seeds every role template scoped to the new tenant', function (): void {
    $owner = User::factory()->create();

    $tenant = $this->service->execute($owner, 'Bright Digital');

    $roles = Role::query()->where('team_id', $tenant->getKey())->pluck('name');

    expect($roles)->toContain('Agency Owner', 'Manager', 'Content Creator')
        ->and($roles)->toContain('Portal Approver', 'Portal Viewer');
});

it('grants the owner every tenant permission but no platform permission', function (): void {
    $owner = User::factory()->create();
    $tenant = $this->service->execute($owner, 'Bright Digital');

    actingForTenant($tenant);
    $owner = $owner->fresh();

    expect($owner->can('posts.publish'))->toBeTrue()
        ->and($owner->can('billing.manage'))->toBeTrue()
        ->and($owner->can('social_credentials.manage'))->toBeTrue()
        // Platform permissions must never reach a tenant role -- that would
        // let an agency owner grant themselves cross-tenant access.
        ->and($owner->can('platform.tenants.manage'))->toBeFalse()
        ->and($owner->can('platform.impersonate'))->toBeFalse();
});

it('opens a credit account with a ledger-backed zero balance', function (): void {
    $owner = User::factory()->create();
    $tenant = $this->service->execute($owner, 'Bright Digital');

    $account = AiCreditAccount::query()->acrossTenants()
        ->where('tenant_id', $tenant->getKey())->first();

    expect($account)->not->toBeNull()
        ->and($account->balance)->toBe(0)
        ->and($account->available())->toBe(0)
        ->and($account->period_end)->not->toBeNull();
});

it('generates a unique slug when the name collides', function (): void {
    $a = $this->service->execute(User::factory()->create(), 'Same Name');
    $b = $this->service->execute(User::factory()->create(), 'Same Name');

    expect($a->slug)->not->toBe($b->slug);
});

it('refuses to hand out a reserved slug', function (): void {
    $tenant = $this->service->execute(User::factory()->create(), 'admin');

    expect($tenant->slug)->not->toBeIn(config('tenancy.reserved_slugs'));
});

it('does not reuse the slug of a soft-deleted tenant', function (): void {
    $first = $this->service->execute(User::factory()->create(), 'Vanishing Co');
    $slug = $first->slug;
    $first->delete();

    $second = $this->service->execute(User::factory()->create(), 'Vanishing Co');

    expect($second->slug)->not->toBe($slug);
});

it('isolates roles between tenants with the same role name', function (): void {
    $tenantA = $this->service->execute(User::factory()->create(), 'Agency A');
    $tenantB = $this->service->execute(User::factory()->create(), 'Agency B');

    $managerA = Role::query()->where('team_id', $tenantA->getKey())->where('name', 'Manager')->first();
    $managerB = Role::query()->where('team_id', $tenantB->getKey())->where('name', 'Manager')->first();

    expect($managerA)->not->toBeNull()
        ->and($managerB)->not->toBeNull()
        ->and($managerA->getKey())->not->toBe($managerB->getKey());
});

it('does not grant a user permissions in a tenant they do not belong to', function (): void {
    $ownerA = User::factory()->create();
    $tenantA = $this->service->execute($ownerA, 'Agency A');
    $tenantB = $this->service->execute(User::factory()->create(), 'Agency B');

    // Owner of A, evaluated in B's team context, holds nothing.
    actingForTenant($tenantB);

    expect($ownerA->fresh()->can('posts.publish'))->toBeFalse();

    actingForTenant($tenantA);
    expect($ownerA->fresh()->can('posts.publish'))->toBeTrue();
});

it('aborts and rolls back when the owner role template is missing', function (): void {
    $owner = User::factory()->create();
    $before = Tenant::query()->count();

    // Without the owner role, assignRole() would receive null -- which spatie
    // silently ignores, leaving a tenant nobody can administer. The service
    // must refuse instead.
    config()->set('permissions.roles', []);

    expect(fn () => $this->service->execute($owner, 'Doomed Agency'))
        ->toThrow(MissingOwnerRoleException::class);

    expect(Tenant::query()->count())->toBe($before)
        ->and(AiCreditAccount::query()->acrossTenants()->count())->toBe(0);
});
