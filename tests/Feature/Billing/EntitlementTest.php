<?php

declare(strict_types=1);

use App\Domain\Billing\Entitlements\Entitlement;
use App\Domain\Billing\Entitlements\EntitlementResolver;
use App\Domain\Billing\Entitlements\Enums\EntitlementType;
use App\Domain\Billing\Entitlements\Exceptions\EntitlementExceeded;
use App\Domain\Customers\Models\Customer;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->resolver = app(EntitlementResolver::class);
    $this->tenant = Tenant::factory()->create();
});

function makePlanWithFeature(string $key, string $type, ?int $value): int
{
    $planId = DB::table('plans')->insertGetId([
        'ulid' => (string) Str::ulid(),
        'name' => 'Test Plan',
        'slug' => 'test-plan-'.Str::lower(Str::random(6)),
        'is_public' => true,
        'is_active' => true,
        'trial_days' => 7,
        'sort_order' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('plan_features')->insert([
        'plan_id' => $planId,
        'key' => $key,
        'value_type' => $type,
        'value' => $value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $planId;
}

function subscribe(Tenant $tenant, int $planId, string $status = 'active'): void
{
    DB::table('subscriptions')->insert([
        'ulid' => (string) Str::ulid(),
        'tenant_id' => $tenant->getKey(),
        'plan_id' => $planId,
        'status' => $status,
        'gateway' => 'manual',
        'quantity' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

// --------------------------------------------------------------- precedence

it('falls back to the config default with no subscription', function (): void {
    $entitlement = $this->resolver->value($this->tenant, 'brands.max');

    expect($entitlement->source)->toBe('default')
        ->and($entitlement->limit())->toBe((int) config('entitlements.keys')['brands.max']['default']);
});

it('prefers the plan feature over the default', function (): void {
    subscribe($this->tenant, makePlanWithFeature('brands.max', 'limit', 25));

    $entitlement = $this->resolver->value($this->tenant, 'brands.max');

    expect($entitlement->source)->toBe('plan')
        ->and($entitlement->limit())->toBe(25);
});

it('prefers a tenant override over the plan', function (): void {
    subscribe($this->tenant, makePlanWithFeature('brands.max', 'limit', 25));

    DB::table('subscription_overrides')->insert([
        'tenant_id' => $this->tenant->getKey(),
        'key' => 'brands.max',
        'value_type' => 'limit',
        'value' => 100,
        'reason' => 'Enterprise negotiation',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $entitlement = $this->resolver->value($this->tenant, 'brands.max');

    // The whole point of overrides: raise one tenant's ceiling with no plan
    // migration and no bespoke plan.
    expect($entitlement->source)->toBe('override')
        ->and($entitlement->limit())->toBe(100);
});

it('ignores an expired override', function (): void {
    subscribe($this->tenant, makePlanWithFeature('brands.max', 'limit', 25));

    DB::table('subscription_overrides')->insert([
        'tenant_id' => $this->tenant->getKey(),
        'key' => 'brands.max',
        'value_type' => 'limit',
        'value' => 100,
        'reason' => 'Temporary bump',
        'expires_at' => now()->subDay(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($this->resolver->value($this->tenant, 'brands.max')->source)->toBe('plan');
});

it('still grants entitlements while past due or in grace', function (string $status): void {
    subscribe($this->tenant, makePlanWithFeature('brands.max', 'limit', 25), $status);

    // Revoking product the moment a card fails turns a recoverable billing
    // lapse into a cancellation.
    expect($this->resolver->value($this->tenant, 'brands.max')->limit())->toBe(25);
})->with(['past_due', 'grace', 'trialing']);

it('stops granting entitlements once the subscription is terminal', function (string $status): void {
    subscribe($this->tenant, makePlanWithFeature('brands.max', 'limit', 25), $status);

    expect($this->resolver->value($this->tenant, 'brands.max')->source)->toBe('default');
})->with(['cancelled', 'expired']);

// ------------------------------------------------------------------ unlimited

it('treats an unlimited entitlement as always permitting', function (): void {
    subscribe($this->tenant, makePlanWithFeature('brands.max', 'unlimited', null));

    $entitlement = $this->resolver->value($this->tenant, 'brands.max');

    expect($entitlement->isUnlimited())->toBeTrue()
        ->and($entitlement->permits(999_999))->toBeTrue();
});

// ----------------------------------------------------------------- enforcement

it('throws once usage reaches the limit', function (): void {
    subscribe($this->tenant, makePlanWithFeature('brands.max', 'limit', 2));

    Customer::factory()->count(2)->create(['tenant_id' => $this->tenant->getKey()]);
    $this->resolver->forget($this->tenant);

    expect(fn () => $this->resolver->guard($this->tenant, 'brands.max'))
        ->toThrow(EntitlementExceeded::class);
});

it('names the limit that was hit so the UI can offer a specific upgrade', function (): void {
    subscribe($this->tenant, makePlanWithFeature('brands.max', 'limit', 1));
    Customer::factory()->create(['tenant_id' => $this->tenant->getKey()]);
    $this->resolver->forget($this->tenant);

    try {
        $this->resolver->guard($this->tenant, 'brands.max');
        $this->fail('Expected EntitlementExceeded.');
    } catch (EntitlementExceeded $e) {
        expect($e->key())->toBe('brands.max')
            ->and($e->getMessage())->toContain('Customer brands');
    }
});

it('does not count archived brands against the limit', function (): void {
    subscribe($this->tenant, makePlanWithFeature('brands.max', 'limit', 2));

    Customer::factory()->create(['tenant_id' => $this->tenant->getKey()]);
    Customer::factory()->archived()->create(['tenant_id' => $this->tenant->getKey()]);
    $this->resolver->forget($this->tenant);

    expect($this->resolver->currentUsage($this->tenant, 'brands.max'))->toBe(1);
});

it('counts usage per tenant, never across them', function (): void {
    $other = Tenant::factory()->create();
    Customer::factory()->count(3)->create(['tenant_id' => $other->getKey()]);

    expect($this->resolver->currentUsage($this->tenant, 'brands.max'))->toBe(0);
});

// ---------------------------------------------------------------------- keys

it('rejects an unknown entitlement key rather than granting no limit', function (): void {
    expect(fn () => $this->resolver->value($this->tenant, 'brands.maximum'))
        ->toThrow(InvalidArgumentException::class);
});

// -------------------------------------------------------------- cache safety

/*
 | Regression: entitlements used to be cached as a serialized Entitlement
 | object. That is unreadable on every real cache store, because Laravel 13
 | ships `cache.serializable_classes => false` -- unserialize is called with
 | `allowed_classes: false`, so the object comes back as __PHP_Incomplete_Class
 | and fatals against the `: Entitlement` return type. Brands, billing and every
 | limit check 500'd on the second request.
 |
 | The whole test suite missed it because the `array` store defaults to
 | `serialize => false` and hands objects back by reference, so nothing was ever
 | serialized. These tests turn serialization on to close that gap.
 */
describe('cache round trip on a serializing store', function (): void {
    beforeEach(function (): void {
        config()->set('cache.stores.array.serialize', true);
        config()->set('cache.serializable_classes', false);
        config()->set('entitlements.cache.enabled', true);

        // Rebuild the store so the new config is actually applied.
        app('cache')->forgetDriver('array');

        $this->resolver = app(EntitlementResolver::class);
    });

    it('survives a cache round trip when the store refuses to unserialize classes', function (): void {
        subscribe($this->tenant, makePlanWithFeature('brands.max', 'limit', 25));

        $first = $this->resolver->value($this->tenant, 'brands.max');
        $second = $this->resolver->value($this->tenant, 'brands.max'); // served from cache

        expect($second)->toBeInstanceOf(Entitlement::class)
            ->and($second->value)->toBe($first->value)
            ->and($second->type)->toBe($first->type)
            ->and($second->source)->toBe('plan')
            ->and($second->limit())->toBe(25);
    });

    it('never writes a PHP object into the cache', function (): void {
        subscribe($this->tenant, makePlanWithFeature('brands.max', 'limit', 25));

        $this->resolver->value($this->tenant, 'brands.max');

        $raw = Cache::get(
            config('entitlements.cache.prefix', 'entitlements').':'.$this->tenant->getKey().':brands.max'
        );

        expect($raw)->toBeArray('Entitlements must be cached as scalars, not as a serialized object.');
    });

    it('treats an unreadable cache entry as a miss instead of fatalling', function (): void {
        subscribe($this->tenant, makePlanWithFeature('brands.max', 'limit', 25));

        // Exactly what a pre-fix deploy leaves behind: a cache entry that reads
        // back as __PHP_Incomplete_Class.
        Cache::put(
            config('entitlements.cache.prefix', 'entitlements').':'.$this->tenant->getKey().':brands.max',
            new Entitlement(
                'brands.max',
                EntitlementType::Limit,
                25,
                'plan',
            ),
            3600,
        );

        expect($this->resolver->value($this->tenant, 'brands.max')->limit())->toBe(25);
    });
});
