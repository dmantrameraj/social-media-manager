<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\Subscriptions\SubscriptionLifecycleService;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Services\ProvisionTenantService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    seedPermissions();
    $this->lifecycle = app(SubscriptionLifecycleService::class);
});

function tenantOnTrial(int $daysAgo): Tenant
{
    $tenant = app(ProvisionTenantService::class)
        ->execute(User::factory()->create(), 'Agency '.Str::random(5));

    $tenant->forceFill([
        'status' => TenantStatus::Trialing->value,
        'trial_ends_at' => now()->subDays($daysAgo),
    ])->save();

    return $tenant->fresh();
}

it('leaves a live trial alone', function (): void {
    $tenant = app(ProvisionTenantService::class)
        ->execute(User::factory()->create(), 'Live Trial');

    $this->lifecycle->run();

    expect($tenant->fresh()->status)->toBe(TenantStatus::Trialing);
});

it('moves an expired trial into grace, not straight to suspension', function (): void {
    $tenant = tenantOnTrial(daysAgo: 1);

    $this->lifecycle->run();

    // Grace exists so a trial ending on a Friday night does not lock the
    // customer out before anyone can act.
    expect($tenant->fresh()->status)->toBe(TenantStatus::Grace);
});

it('converts an expired trial that has a paid subscription', function (): void {
    $tenant = tenantOnTrial(daysAgo: 1);

    Subscription::query()->forceCreate([
        'ulid' => (string) Str::ulid(),
        'tenant_id' => $tenant->getKey(),
        'plan_id' => DB::table('plans')->insertGetId([
            'ulid' => (string) Str::ulid(), 'name' => 'Pro',
            'slug' => 'pro-'.Str::lower(Str::random(5)),
            'is_public' => true, 'is_active' => true, 'trial_days' => 0, 'sort_order' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]),
        'status' => SubscriptionStatus::Active->value,
        'gateway' => 'razorpay',
        'quantity' => 1,
        'current_period_end' => now()->addMonth(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->lifecycle->run();

    expect($tenant->fresh()->status)->toBe(TenantStatus::Active);
});

it('suspends only once the grace window has elapsed', function (): void {
    $graceDays = (int) config('billing.grace_days');

    $stillInGrace = tenantOnTrial(daysAgo: 1);
    $graceElapsed = tenantOnTrial(daysAgo: $graceDays + 2);

    $this->lifecycle->run();   // moves both into grace
    $this->lifecycle->run();   // evaluates the grace deadline

    expect($stillInGrace->fresh()->status)->toBe(TenantStatus::Grace)
        ->and($graceElapsed->fresh()->status)->toBe(TenantStatus::Suspended);
});

it('is idempotent when run repeatedly', function (): void {
    $tenant = tenantOnTrial(daysAgo: 1);

    $this->lifecycle->run();
    $statusAfterFirst = $tenant->fresh()->status;

    $second = $this->lifecycle->run();

    // A scheduler that overlaps or retries is normal; a second pass must
    // change nothing.
    expect($tenant->fresh()->status)->toBe($statusAfterFirst)
        ->and($second['trial_expired'])->toBe(0);
});

it('blocks product access once suspended but keeps the data', function (): void {
    $tenant = tenantOnTrial(daysAgo: (int) config('billing.grace_days') + 2);

    $this->lifecycle->run();
    $this->lifecycle->run();

    $tenant = $tenant->fresh();

    expect($tenant->status)->toBe(TenantStatus::Suspended)
        ->and($tenant->permitsProductAccess())->toBeFalse()
        ->and($tenant->permitsPublishing())->toBeFalse()
        // Suspension is not deletion.
        ->and($tenant->exists)->toBeTrue();
});

it('keeps publishing available during grace by default', function (): void {
    $tenant = tenantOnTrial(daysAgo: 1);
    $this->lifecycle->run();

    $tenant = $tenant->fresh();

    // Cutting off a client's scheduled posts over an expired card damages the
    // agency's relationship with their own customer.
    expect($tenant->status)->toBe(TenantStatus::Grace)
        ->and($tenant->permitsProductAccess())->toBeTrue()
        ->and($tenant->permitsPublishing())->toBeTrue();
});

it('stops publishing in grace when the policy is turned off', function (): void {
    config()->set('billing.publish_during_grace', false);

    $tenant = tenantOnTrial(daysAgo: 1);
    $this->lifecycle->run();

    expect($tenant->fresh()->permitsPublishing())->toBeFalse();
});

it('reactivates a suspended tenant and clears the retention clock', function (): void {
    $tenant = tenantOnTrial(daysAgo: (int) config('billing.grace_days') + 2);
    $this->lifecycle->run();
    $this->lifecycle->run();

    $this->lifecycle->reactivate($tenant->fresh());

    $tenant = $tenant->fresh();

    expect($tenant->status)->toBe(TenantStatus::Active)
        ->and($tenant->suspended_at)->toBeNull()
        // A tenant that paid must not stay queued for anonymisation.
        ->and($tenant->purge_after)->toBeNull();
});

it('starts the retention clock on cancellation rather than deleting', function (): void {
    $tenant = app(ProvisionTenantService::class)
        ->execute(User::factory()->create(), 'Leaving Agency');

    $this->lifecycle->cancel($tenant);

    $tenant = $tenant->fresh();

    expect($tenant->status)->toBe(TenantStatus::Cancelled)
        ->and($tenant->purge_after)->not->toBeNull()
        ->and($tenant->purge_after->isAfter(now()->addDays(59)))->toBeTrue()
        // Tenant is the root of the hierarchy, so it has no tenant scope to
        // bypass -- the row is simply still there.
        ->and(Tenant::query()->whereKey($tenant->getKey())->exists())->toBeTrue();
});

it('moves a lapsed billing period into grace', function (): void {
    // This path was previously untested and hid a runtime bug that only
    // static analysis caught.
    $tenant = app(ProvisionTenantService::class)
        ->execute(User::factory()->create(), 'Lapsed Agency');
    $tenant->forceFill(['status' => TenantStatus::Active->value])->save();

    $planId = DB::table('plans')->insertGetId([
        'ulid' => (string) Str::ulid(), 'name' => 'Pro',
        'slug' => 'pro-'.Str::lower(Str::random(5)),
        'is_public' => true, 'is_active' => true, 'trial_days' => 0, 'sort_order' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    Subscription::query()->forceCreate([
        'ulid' => (string) Str::ulid(),
        'tenant_id' => $tenant->getKey(),
        'plan_id' => $planId,
        'status' => SubscriptionStatus::Active->value,
        'gateway' => 'razorpay',
        'quantity' => 1,
        'current_period_end' => now()->subDay(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $result = $this->lifecycle->run();

    $subscription = Subscription::query()->acrossTenants()->firstOrFail();

    expect($result['period_expired'])->toBe(1)
        ->and($tenant->fresh()->status)->toBe(TenantStatus::Grace)
        ->and($subscription->status)->toBe(SubscriptionStatus::Grace)
        ->and($subscription->grace_ends_at)->not->toBeNull();
});

it('audits every lifecycle transition', function (): void {
    $tenant = tenantOnTrial(daysAgo: 1);

    $this->lifecycle->run();

    expect(AuditLog::query()->where('action', 'tenant.trial_expired')->exists())->toBeTrue();
});

it('does not touch tenants belonging to a different lifecycle state', function (): void {
    $active = app(ProvisionTenantService::class)
        ->execute(User::factory()->create(), 'Healthy Agency');
    $active->forceFill(['status' => TenantStatus::Active->value])->save();

    $this->lifecycle->run();

    expect($active->fresh()->status)->toBe(TenantStatus::Active);
});
