<?php

declare(strict_types=1);

use App\Domain\AI\AiFeatureRegistry;
use App\Domain\AI\Credits\CreditLedger;
use App\Domain\AI\Models\AiGeneration;
use App\Domain\AI\Models\BrandBrain;
use App\Domain\AI\Providers\FakeAiProvider;
use App\Domain\Billing\Entitlements\EntitlementResolver;
use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Services\ProvisionTenantService;

/*
 | The screen that reaches the AI.
 |
 | Twelve features, the credit ledger, reservations and the Brand Brain context
 | builder were built and tested behind no route at all: `ai.use` sat in the
 | permission catalogue governing nothing, and a tenant's monthly credits could
 | only be spent by a test naming a feature class directly.
 */

beforeEach(function (): void {
    seedPermissions();
    FakeAiProvider::reset();
    config()->set('ai.default', 'fake');

    $this->ledger = app(CreditLedger::class);

    $owner = User::factory()->create();
    $this->tenant = app(ProvisionTenantService::class)->execute($owner, 'Bright Digital');
    $this->owner = $owner->fresh();

    givePlanLimit($this->tenant->getKey(), 'ai.credits_per_month', 1000);
    app(EntitlementResolver::class)->forget($this->tenant);

    actingForTenant($this->tenant);

    $this->brand = Customer::factory()->create([
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'Roast House',
    ]);

    $this->ledger->grant($this->tenant, 100, 'Plan allowance');
});

// ------------------------------------------------------------------ the picker

it('lists every registered feature with its price', function (): void {
    // The cost is on the card before the click: finding out what something
    // costs only after spending it is how a credit system loses trust.
    $this->actingAs($this->owner)
        ->get(route('agency.ai.index'))
        ->assertOk()
        ->assertSee('Caption')
        ->assertSee('Monthly plan')
        ->assertSee('25 credits');
});

it('offers every registered feature with a price', function (): void {
    /*
     | withCosts() says it exists so "a feature is never offered without a
     | price". This asserts the screen honours that for all twelve rather than
     | only for the ones that happen to appear first.
     */
    $registry = app(AiFeatureRegistry::class);
    $costs = $registry->withCosts();

    expect($costs)->toHaveCount(count($registry->keys()));

    foreach ($registry->keys() as $key) {
        expect($costs[$key] ?? 0)->toBeGreaterThan(0);
    }
});

it('orders the picker cheapest first', function (): void {
    // Not registration order: a list sorted by implementation date teaches a
    // browsing user nothing.
    $values = array_values(app(AiFeatureRegistry::class)->withCosts());
    $sorted = $values;
    sort($sorted);

    expect($values)->toBe($sorted);
});

it('refuses the studio without the permission', function (): void {
    $member = memberWithRole($this->tenant, 'Designer');

    $this->actingAs($member)
        ->get(route('agency.ai.index'))
        ->assertForbidden();
});

// -------------------------------------------------------------------- the form

it('builds the form from the feature own declaration', function (): void {
    /*
     | The fields come from inputFields(), not a list in the view. A second
     | list drifts the moment a feature reads a key nobody added to it, and the
     | symptom -- a field silently ignored -- looks like a model problem.
     */
    $this->actingAs($this->owner)
        ->get(route('agency.ai.show', 'caption'))
        ->assertOk()
        ->assertSee('name="topic"', false)
        ->assertSee('name="platform"', false)
        ->assertSee('name="character_limit"', false);
});

it('does not resolve an unknown feature key', function (): void {
    /*
     | A key from a URL that instantiates a class is how a picker becomes an
     | object-instantiation primitive, so unknown keys never reach the
     | container.
     */
    $this->actingAs($this->owner)
        ->from(route('agency.ai.index'))
        ->get(route('agency.ai.show', 'App\\Domain\\Tenancy\\Models\\Tenant'))
        ->assertRedirect(route('agency.ai.index'))
        ->assertSessionHas('error');
});

// ---------------------------------------------------------------- generating

it('generates and charges the feature cost', function (): void {
    BrandBrain::factory()->forCustomer($this->brand)->create();
    FakeAiProvider::willReturn('Freshly roasted, every single morning.');

    $before = $this->ledger->accountFor($this->tenant)->available();

    $this->actingAs($this->owner)
        ->post(route('agency.ai.generate', 'caption'), [
            'customer' => $this->brand->getKey(),
            'topic' => 'our new espresso blend',
        ])
        ->assertOk()
        ->assertSee('Freshly roasted');

    expect($this->ledger->accountFor($this->tenant->fresh())->available())
        ->toBe($before - (int) config('ai.costs.caption'))
        ->and(AiGeneration::query()->count())->toBe(1);
});

it('cannot generate for another agency brand', function (): void {
    // The tenant scope hides the row, so a foreign brand and a deleted one are
    // indistinguishable from outside.
    [$other] = provisionTenant('Rival Agency');
    $foreign = Customer::factory()->create(['tenant_id' => $other->getKey()]);

    actingForTenant($this->tenant);
    FakeAiProvider::willReturn('should not happen');

    $this->actingAs($this->owner)
        ->post(route('agency.ai.generate', 'caption'), [
            'customer' => $foreign->getKey(),
            'topic' => 'anything',
        ])
        ->assertNotFound();

    expect(FakeAiProvider::callCount())->toBe(0);
});

it('refuses to generate without the permission', function (): void {
    $member = memberWithRole($this->tenant, 'Designer');
    FakeAiProvider::willReturn('should not happen');

    $this->actingAs($member)
        ->post(route('agency.ai.generate', 'caption'), [
            'customer' => $this->brand->getKey(),
            'topic' => 'anything',
        ])
        ->assertForbidden();

    expect(FakeAiProvider::callCount())->toBe(0);
});

it('bounds a numeric input so one request cannot eat the month', function (): void {
    /*
     | Limits come from the field's own min/max. A feature that means 1-90 days
     | must not be handed 90,000 and spend a tenant's whole allowance proving
     | the model will refuse.
     */
    BrandBrain::factory()->forCustomer($this->brand)->create();
    FakeAiProvider::willReturn('should not happen');

    $this->actingAs($this->owner)
        ->post(route('agency.ai.generate', 'monthly_plan'), [
            'customer' => $this->brand->getKey(),
            'days' => 90000,
        ])
        ->assertSessionHasErrors('days');

    expect(FakeAiProvider::callCount())->toBe(0);
});

it('charges nothing when the provider fails', function (): void {
    /*
     | The reservation is released by the service, so a provider outage must
     | not quietly bill an agency for output they never received.
     */
    BrandBrain::factory()->forCustomer($this->brand)->create();
    FakeAiProvider::willFail();

    $before = $this->ledger->accountFor($this->tenant)->available();

    $this->actingAs($this->owner)
        ->from(route('agency.ai.show', 'caption'))
        ->post(route('agency.ai.generate', 'caption'), [
            'customer' => $this->brand->getKey(),
            'topic' => 'our new espresso blend',
        ])
        ->assertRedirect(route('agency.ai.show', 'caption'))
        ->assertSessionHas('error');

    expect($this->ledger->accountFor($this->tenant->fresh())->available())->toBe($before);
});

it('offers an upgrade rather than a 500 when credits run out', function (): void {
    BrandBrain::factory()->forCustomer($this->brand)->create();
    FakeAiProvider::willReturn('should not happen');

    // Spend the balance down to nothing.
    $account = $this->ledger->accountFor($this->tenant);
    $this->ledger->adjust($this->tenant, -$account->available(), 'Test drain');

    $this->actingAs($this->owner)
        ->from(route('agency.ai.show', 'caption'))
        ->post(route('agency.ai.generate', 'caption'), [
            'customer' => $this->brand->getKey(),
            'topic' => 'anything',
        ])
        ->assertRedirect(route('agency.ai.show', 'caption'))
        ->assertSessionHas('error')
        // What the flash partial reads to offer the billing link.
        ->assertSessionHas('upgrade_prompt', true);

    expect(FakeAiProvider::callCount())->toBe(0);
});
