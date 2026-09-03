<?php

declare(strict_types=1);

use App\Domain\Billing\Entitlements\EntitlementResolver;
use App\Domain\Billing\Entitlements\Enums\EntitlementType;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Services\ProvisionTenantService;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | DatabaseSeeder was still the Laravel skeleton's default -- one hardcoded test
 | user -- so a fresh clone had no plans at all. Every entitlement resolved from
 | config defaults and no subscription could exist, because subscriptions.plan_id
 | is NOT NULL.
 */

beforeEach(function (): void {
    seedPermissions();
    $this->seed(PlanSeeder::class);
});

/** Put a tenant on a seeded plan, the way manual activation does. */
function subscribeTo(Tenant $tenant, string $slug): void
{
    DB::table('subscriptions')->insert([
        'ulid' => (string) Str::ulid(),
        'tenant_id' => $tenant->getKey(),
        'plan_id' => DB::table('plans')->where('slug', $slug)->value('id'),
        'status' => 'active',
        'gateway' => 'manual',
        'quantity' => 1,
        'current_period_start' => now(),
        'current_period_end' => now()->addMonth(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

// ------------------------------------------------------------------- the tiers

it('seeds the four reference tiers', function (): void {
    expect(DB::table('plans')->pluck('slug')->all())
        ->toEqualCanonicalizing(['starter', 'professional', 'agency', 'enterprise']);
});

it('keeps enterprise off the pricing page', function (): void {
    // Its credits and storage are negotiated per deal, so it is not a plan
    // anyone can self-select.
    expect(DB::table('plans')->where('slug', 'enterprise')->value('is_public'))->toBe(0);
});

it('matches the reference table in the billing doc', function (string $slug, string $key, int $expected): void {
    $value = DB::table('plan_features')
        ->join('plans', 'plans.id', '=', 'plan_features.plan_id')
        ->where('plans.slug', $slug)
        ->where('plan_features.key', $key)
        ->value('plan_features.value');

    expect((int) $value)->toBe($expected);
})->with([
    ['starter', 'brands.max', 5],
    ['professional', 'brands.max', 15],
    ['agency', 'brands.max', 25],
    ['starter', 'team_members.max', 2],
    ['professional', 'team_members.max', 5],
    ['agency', 'team_members.max', 10],
    ['starter', 'posts.scheduled_per_month', 100],
    ['agency', 'ai.credits_per_month', 2000],
]);

it('stores enterprise limits as unlimited rather than leaving them out', function (): void {
    /*
     | Omitting them would fall through to the config default -- 1 GiB of
     | storage -- making Enterprise strictly worse than Agency until somebody
     | noticed.
     */
    $row = DB::table('plan_features')
        ->join('plans', 'plans.id', '=', 'plan_features.plan_id')
        ->where('plans.slug', 'enterprise')
        ->where('plan_features.key', 'storage.max_bytes')
        ->first();

    expect($row->value_type)->toBe(EntitlementType::Unlimited->value)
        ->and($row->value)->toBeNull();
});

it('stores booleans as 1 and 0 in the value column', function (): void {
    // Entitlement::isEnabled() reads them from the same column as limits.
    $analytics = fn (string $slug) => DB::table('plan_features')
        ->join('plans', 'plans.id', '=', 'plan_features.plan_id')
        ->where('plans.slug', $slug)
        ->where('plan_features.key', 'analytics.enabled')
        ->value('plan_features.value');

    expect((int) $analytics('starter'))->toBe(0)
        ->and((int) $analytics('professional'))->toBe(1);
});

// --------------------------------------------------------------- re-runnability

it('does not duplicate anything when run again', function (): void {
    $plans = DB::table('plans')->count();
    $features = DB::table('plan_features')->count();

    $this->seed(PlanSeeder::class);

    expect(DB::table('plans')->count())->toBe($plans)
        ->and(DB::table('plan_features')->count())->toBe($features);
});

it('leaves an edited value alone', function (): void {
    /*
     | The billing doc calls these "seed values, not constants... Super Admin
     | edits them without a deploy". A seeder that overwrote on every deploy
     | would silently revert a deliberate change.
     */
    DB::table('plan_features')
        ->join('plans', 'plans.id', '=', 'plan_features.plan_id')
        ->where('plans.slug', 'starter')
        ->where('plan_features.key', 'brands.max')
        ->update(['plan_features.value' => 99]);

    $this->seed(PlanSeeder::class);

    $value = DB::table('plan_features')
        ->join('plans', 'plans.id', '=', 'plan_features.plan_id')
        ->where('plans.slug', 'starter')
        ->where('plan_features.key', 'brands.max')
        ->value('plan_features.value');

    expect((int) $value)->toBe(99);
});

// -------------------------------------------------------------------- the point

it('makes entitlements resolve from the plan instead of config defaults', function (): void {
    // The whole reason this matters: without a plan, every tenant silently ran
    // on the fallback path rather than the one paying customers are on.
    $owner = User::factory()->create();
    $tenant = app(ProvisionTenantService::class)->execute($owner, 'Seeded Agency');

    subscribeTo($tenant, 'starter');

    $entitlement = app(EntitlementResolver::class)->value($tenant, 'brands.max');

    expect($entitlement->source)->toBe('plan')
        ->and($entitlement->limit())->toBe(5);
});

it('refuses a feature key that is not in the catalogue', function (): void {
    /*
     | A seeder writing straight to the table bypasses whatever validates an
     | admin edit, and a key nothing reads is a limit that looks configured and
     | is silently unenforced.
     */
    DB::table('plan_features')->delete();
    DB::table('plans')->delete();

    config(['entitlements.keys' => []]);

    expect(fn () => $this->seed(PlanSeeder::class))
        ->toThrow(RuntimeException::class);
});
