<?php

declare(strict_types=1);

use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Tenant;
use Database\Seeders\DemoTenantSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\DB;

/*
 | The Phase 1 sign-off asks that `migrate:fresh --seed` produce a working demo
 | tenant. It produced one hardcoded test user and nothing else.
 */

beforeEach(function (): void {
    seedPermissions();
    $this->seed(PlanSeeder::class);
});

it('provisions an agency, a brand and a subscription', function (): void {
    $this->seed(DemoTenantSeeder::class);

    $tenant = Tenant::query()->where('name', 'Demo Agency')->first();

    expect($tenant)->not->toBeNull();

    actingForTenant($tenant);

    expect(Customer::query()->count())->toBe(1)
        ->and(DB::table('subscriptions')->where('tenant_id', $tenant->getKey())->count())->toBe(1);
});

it('goes through the real provisioning service', function (): void {
    /*
     | Roles and the credit account come from ProvisionTenantService rather than
     | raw inserts, so the demo tenant matches what a signup produces. A
     | hand-built fixture would drift and hide the bugs a demo exists to surface.
     */
    $this->seed(DemoTenantSeeder::class);

    $tenant = Tenant::query()->where('name', 'Demo Agency')->firstOrFail();
    $owner = User::query()->where('email', config('platform.demo.email'))->firstOrFail();

    setPermissionsTeamId($tenant->getKey());

    expect($owner->fresh()->hasRole('Agency Owner'))->toBeTrue()
        ->and(DB::table('ai_credit_accounts')->where('tenant_id', $tenant->getKey())->exists())
        ->toBeTrue();
});

it('puts the demo tenant on the starter plan', function (): void {
    // Without a subscription the demo would exercise the config fallback rather
    // than the path paying customers are on.
    $this->seed(DemoTenantSeeder::class);

    $tenant = Tenant::query()->where('name', 'Demo Agency')->firstOrFail();

    $slug = DB::table('subscriptions')
        ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
        ->where('subscriptions.tenant_id', $tenant->getKey())
        ->value('plans.slug');

    expect($slug)->toBe('starter');
});

it('does not create a second demo tenant when run again', function (): void {
    $this->seed(DemoTenantSeeder::class);
    $this->seed(DemoTenantSeeder::class);

    expect(Tenant::query()->where('name', 'Demo Agency')->count())->toBe(1);
});

it('refuses to run in production', function (): void {
    /*
     | A demo login is a known account on a public host, and db:seed is one
     | careless deploy hook away from running there.
     */
    app()->detectEnvironment(fn (): string => 'production');

    /*
     | --force because db:seed asks for confirmation in production, and that
     | prompt would answer this test before the seeder's own guard was reached.
     | Forcing past Laravel's check is what proves ours is doing the work.
     */
    $this->artisan('db:seed', [
        '--class' => DemoTenantSeeder::class,
        '--force' => true,
    ])->assertSuccessful();

    expect(Tenant::query()->where('name', 'Demo Agency')->exists())->toBeFalse()
        ->and(User::query()->where('email', config('platform.demo.email'))->exists())->toBeFalse();
});

it('does not ship a fixed password', function (): void {
    // A default password in a seeder becomes the password on somebody's
    // reachable staging box.
    expect(config('platform.demo.password'))->toBeNull();
});
