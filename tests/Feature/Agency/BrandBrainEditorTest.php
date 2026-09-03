<?php

declare(strict_types=1);

use App\Domain\AI\Models\BrandBrain;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Services\ProvisionTenantService;
use App\Support\TenantContext;

beforeEach(function (): void {
    seedPermissions();

    $this->owner = User::factory()->create();
    $this->tenant = app(ProvisionTenantService::class)->execute($this->owner, 'Bright Digital');
    $this->owner = $this->owner->fresh();

    actingForTenant($this->tenant);

    $this->brand = Customer::factory()->create([
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'Roast House',
    ]);
});

function asBrainEditor(User $user)
{
    return test()->actingAs($user, 'web')->withSession([
        config('tenancy.resolution.session_key', 'tenant_id') => test()->tenant->getKey(),
    ]);
}

/** @return array<string, string> */
function brainPayload(array $overrides = []): array
{
    return array_merge([
        'business_description' => 'A speciality coffee roaster in Leeds.',
        'industry' => 'Food and drink',
        'brand_tone' => 'Warm, plain-spoken, never salesy',
        'target_audience' => "Home baristas\nCafé owners",
        'products' => "Single-origin beans\nEspresso blend",
        'forbidden_words' => "cheap\nbargain",
    ], $overrides);
}

// -------------------------------------------------------------------- access

it('opens the editor without creating a row', function (): void {
    /*
     | An empty Brand Brain that EXISTS is indistinguishable from a configured
     | one when counting, and it changes what completeness means. Opening a page
     | must not write.
     */
    asBrainEditor($this->owner)
        ->get(route('agency.brands.brain', $this->brand))
        ->assertOk()
        ->assertSee('Profile completeness');

    expect(BrandBrain::query()->count())->toBe(0);
});

it('refuses someone without the AI permission', function (): void {
    // Gated on ai.manage_brand_brain, not customers.update: editing this
    // changes what the AI says in the client's name on every future post.
    $designer = memberWithRole($this->tenant, 'Designer');

    asBrainEditor($designer)
        ->get(route('agency.brands.brain', $this->brand))
        ->assertForbidden();
});

it('answers 404 for another tenant is brand', function (): void {
    $otherOwner = User::factory()->create();
    $otherTenant = app(ProvisionTenantService::class)->execute($otherOwner, 'Rival Agency');

    app(TenantContext::class)->set($otherTenant);
    $foreignBrand = Customer::factory()->create(['tenant_id' => $otherTenant->getKey()]);
    actingForTenant($this->tenant);

    asBrainEditor($this->owner)
        ->get(route('agency.brands.brain', $foreignBrand))
        ->assertNotFound();
});

// --------------------------------------------------------------------- saving

it('saves a profile', function (): void {
    // Until this existed, all twelve AI features were built and unusable: a
    // Brand Brain could only be filled by writing to the database.
    asBrainEditor($this->owner)
        ->put(route('agency.brands.brain.update', $this->brand), brainPayload())
        ->assertRedirect();

    $brain = BrandBrain::query()->where('customer_id', $this->brand->getKey())->first();

    expect($brain)->not->toBeNull()
        ->and($brain->business_description)->toContain('speciality coffee')
        ->and($brain->tenant_id)->toBe($this->tenant->getKey());
});

it('splits a textarea into a list', function (): void {
    asBrainEditor($this->owner)->put(
        route('agency.brands.brain.update', $this->brand),
        brainPayload(['products' => "Beans\n\n  Grinder  \nMugs\n"]),
    );

    expect(BrandBrain::query()->first()->products)->toBe(['Beans', 'Grinder', 'Mugs']);
});

it('drops a duplicate however it was capitalised', function (): void {
    // "Vegan" and "vegan" in a forbidden-words list are the same instruction,
    // and repeating an item in a prompt does not make the model obey it harder.
    asBrainEditor($this->owner)->put(
        route('agency.brands.brain.update', $this->brand),
        brainPayload(['forbidden_words' => "cheap\nCheap\nCHEAP\nbargain"]),
    );

    expect(BrandBrain::query()->first()->forbidden_words)->toBe(['cheap', 'bargain']);
});

it('stores an empty field as null, not as a blank string', function (): void {
    // '' would read as "filled in" and inflate the completeness figure people
    // rely on to judge why output is generic.
    asBrainEditor($this->owner)->put(
        route('agency.brands.brain.update', $this->brand),
        brainPayload(['industry' => '   ']),
    );

    expect(BrandBrain::query()->first()->industry)->toBeNull();
});

it('keeps a language rather than blanking it', function (): void {
    asBrainEditor($this->owner)->put(
        route('agency.brands.brain.update', $this->brand),
        brainPayload(['primary_language' => '']),
    );

    expect(BrandBrain::query()->first()->primary_language)->toBe('en');
});

it('updates rather than duplicating on a second save', function (): void {
    asBrainEditor($this->owner)->put(route('agency.brands.brain.update', $this->brand), brainPayload());
    asBrainEditor($this->owner)->put(
        route('agency.brands.brain.update', $this->brand),
        brainPayload(['industry' => 'Coffee']),
    );

    expect(BrandBrain::query()->count())->toBe(1)
        ->and(BrandBrain::query()->first()->industry)->toBe('Coffee');
});

it('rejects a website that is not a url', function (): void {
    asBrainEditor($this->owner)
        ->put(route('agency.brands.brain.update', $this->brand), brainPayload(['website' => 'not a url']))
        ->assertSessionHasErrors('website');
});

// ------------------------------------------------------------------ feedback

it('reports how complete the profile is', function (): void {
    // Output quality tracks this directly, and without it people conclude the
    // AI is poor when it has simply been told nothing.
    asBrainEditor($this->owner)->put(route('agency.brands.brain.update', $this->brand), brainPayload());

    asBrainEditor($this->owner)
        ->get(route('agency.brands.brain', $this->brand))
        ->assertOk()
        ->assertSee('%', false);

    expect(BrandBrain::query()->first()->completeness())->toBeGreaterThan(0);
});

it('audits the change without copying the profile into the log', function (): void {
    /*
     | Who changed it and how complete it became is what a later "why did it
     | write that?" needs. The values themselves are long free text and the
     | audit log is not the place to duplicate them.
     */
    asBrainEditor($this->owner)->put(route('agency.brands.brain.update', $this->brand), brainPayload());

    $entry = AuditLog::query()->where('action', 'ai.brand_brain_updated')->latest('id')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->actor_id)->toBe($this->owner->getKey())
        ->and($entry->new_values)->toHaveKey('completeness')
        ->and(json_encode($entry->new_values))->not->toContain('speciality coffee');
});

it('is reachable from the brand page', function (): void {
    asBrainEditor($this->owner)
        ->get(route('agency.brands.show', $this->brand))
        ->assertOk()
        ->assertSee('Edit brand brain');
});
