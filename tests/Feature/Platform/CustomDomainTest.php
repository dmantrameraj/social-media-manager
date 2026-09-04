<?php

declare(strict_types=1);

use App\Domain\Billing\Entitlements\EntitlementResolver;
use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\CustomerPortalUser;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Contracts\DnsLookup;
use App\Domain\Platform\Models\BrandingSetting;
use App\Domain\Platform\Models\Domain;
use App\Domain\Tenancy\Services\ProvisionTenantService;

/*
 | Custom portal domains.
 |
 | The `domains` table shipped in Phase 1 as a schema stub with nothing
 | attached: no model, no routes, and no host-based resolution. A client
 | reached their agency's portal only at the platform's own hostname.
 */

/** DNS without the network. A test that depends on live DNS is not a test. */
final class FakeDns implements DnsLookup
{
    /** @var array<string, list<string>> */
    public static array $records = [];

    public static function reset(): void
    {
        self::$records = [];
    }

    /** @param list<string> $values */
    public static function publish(string $hostname, array $values): void
    {
        self::$records[$hostname] = $values;
    }

    public function txtRecords(string $hostname): array
    {
        return self::$records[$hostname] ?? [];
    }
}

beforeEach(function (): void {
    seedPermissions();
    FakeDns::reset();
    app()->bind(DnsLookup::class, FakeDns::class);

    $owner = User::factory()->create();
    $this->tenant = app(ProvisionTenantService::class)->execute($owner, 'Bright Digital');
    $this->owner = $owner->fresh();
    actingForTenant($this->tenant);

    givePlanFlag($this->tenant->getKey(), 'white_label.enabled');
    app(EntitlementResolver::class)->forget($this->tenant);
});

// ------------------------------------------------------------------- managing

it('adds a domain unverified', function (): void {
    // Unverified on purpose: it must resolve nothing until DNS proves the
    // agency controls the name.
    $this->actingAs($this->owner)
        ->from(route('agency.settings.domains'))
        ->post(route('agency.settings.domains.store'), ['hostname' => 'portal.brightdigital.test'])
        ->assertRedirect(route('agency.settings.domains'));

    $domain = Domain::query()->sole();

    expect($domain->hostname)->toBe('portal.brightdigital.test')
        ->and($domain->isVerified())->toBeFalse()
        ->and($domain->verification_token)->not->toBeNull();
});

it('lowercases a hostname', function (): void {
    // DNS is case-insensitive, so PORTAL.example and portal.example are the
    // same name and must not become two rows that resolve differently.
    $this->actingAs($this->owner)
        ->post(route('agency.settings.domains.store'), ['hostname' => 'PORTAL.BrightDigital.test']);

    expect(Domain::query()->sole()->hostname)->toBe('portal.brightdigital.test');
});

it('refuses a hostname another agency already holds', function (): void {
    /*
     | Globally unique, as the table requires: a hostname maps to exactly one
     | agency or it maps to nothing. Without this two tenants could claim the
     | same name and resolution would be a coin toss.
     */
    [$rival] = provisionTenant('Rival Agency');
    actingForTenant($rival);
    Domain::factory()->create([
        'tenant_id' => $rival->getKey(),
        'hostname' => 'portal.contested.test',
    ]);

    actingForTenant($this->tenant);

    $this->actingAs($this->owner)
        ->post(route('agency.settings.domains.store'), ['hostname' => 'portal.contested.test'])
        ->assertSessionHasErrors('hostname');
});

it('refuses a URL rather than a hostname', function (): void {
    $this->actingAs($this->owner)
        ->post(route('agency.settings.domains.store'), ['hostname' => 'https://portal.test/path'])
        ->assertSessionHasErrors('hostname');
});

it('will not add a domain without the entitlement', function (): void {
    givePlanFlag($this->tenant->getKey(), 'white_label.enabled', false);
    app(EntitlementResolver::class)->forget($this->tenant);

    $this->actingAs($this->owner)
        ->post(route('agency.settings.domains.store'), ['hostname' => 'portal.brightdigital.test'])
        ->assertForbidden();

    expect(Domain::query()->count())->toBe(0);
});

// ---------------------------------------------------------------- verifying

it('verifies when the token is published', function (): void {
    $domain = Domain::factory()->create([
        'tenant_id' => $this->tenant->getKey(),
        'hostname' => 'portal.brightdigital.test',
    ]);

    FakeDns::publish('portal.brightdigital.test', ['unrelated', $domain->verification_token]);

    $this->actingAs($this->owner)
        ->post(route('agency.settings.domains.verify', $domain))
        ->assertSessionHas('status');

    expect($domain->fresh()->isVerified())->toBeTrue();
});

it('does not verify on a token that merely contains ours', function (): void {
    /*
     | Exact match, not a substring. A shared TXT record with several values
     | concatenated could otherwise satisfy a token never published for this
     | domain.
     */
    $domain = Domain::factory()->create([
        'tenant_id' => $this->tenant->getKey(),
        'hostname' => 'portal.brightdigital.test',
    ]);

    FakeDns::publish('portal.brightdigital.test', ['prefix-'.$domain->verification_token.'-suffix']);

    $this->actingAs($this->owner)
        ->post(route('agency.settings.domains.verify', $domain))
        ->assertSessionHas('error');

    expect($domain->fresh()->isVerified())->toBeFalse();
});

it('does not verify when nothing is published', function (): void {
    $domain = Domain::factory()->create(['tenant_id' => $this->tenant->getKey()]);

    $this->actingAs($this->owner)
        ->post(route('agency.settings.domains.verify', $domain))
        ->assertSessionHas('error');

    expect($domain->fresh()->isVerified())->toBeFalse();
});

it('cannot verify another agency domain', function (): void {
    [$rival] = provisionTenant('Rival Agency');
    actingForTenant($rival);
    $foreign = Domain::factory()->create(['tenant_id' => $rival->getKey()]);

    actingForTenant($this->tenant);

    $this->actingAs($this->owner)
        ->post(route('agency.settings.domains.verify', $foreign))
        ->assertNotFound();
});

// --------------------------------------------------------- host resolution

it('brands the portal login page from the hostname', function (): void {
    /*
     | Before sign-in, which is the point. A client arriving at their agency's
     | hostname should see that agency, not the platform's name followed by a
     | rebrand once authenticated.
     */
    BrandingSetting::factory()->create([
        'tenant_id' => $this->tenant->getKey(),
        'app_name' => 'Bright Digital Social',
    ]);

    Domain::factory()->verified()->create([
        'tenant_id' => $this->tenant->getKey(),
        'hostname' => 'portal.brightdigital.test',
    ]);

    $this->get('http://portal.brightdigital.test/portal/login')
        ->assertOk()
        ->assertSee('Bright Digital Social');
});

it('ignores an unverified domain', function (): void {
    /*
     | An unverified row is a CLAIM. Resolving on one would let anybody point
     | DNS at this application and be served another agency's portal.
     */
    BrandingSetting::factory()->create([
        'tenant_id' => $this->tenant->getKey(),
        'app_name' => 'Bright Digital Social',
    ]);

    Domain::factory()->create([
        'tenant_id' => $this->tenant->getKey(),
        'hostname' => 'portal.unproven.test',
    ]);

    /*
     | The harness set tenant context in beforeEach and the test request
     | inherits it, which production never does for an unauthenticated portal
     | request. Cleared so the middleware's decision is what is measured
     | rather than a leftover from setup.
     */
    withoutTenantContext();

    $this->get('http://portal.unproven.test/portal/login')
        ->assertOk()
        ->assertDontSee('Bright Digital Social');
});

it('refuses a portal user on another agency domain', function (): void {
    /*
     | The constraint that makes host resolution safe rather than merely
     | convenient: a verified domain must narrow who can use it, not open a
     | second entrance to every tenant's portal.
     */
    [$rival] = provisionTenant('Rival Agency');
    actingForTenant($rival);

    Domain::factory()->verified()->create([
        'tenant_id' => $rival->getKey(),
        'hostname' => 'portal.rival.test',
    ]);

    actingForTenant($this->tenant);

    $brand = Customer::factory()->create(['tenant_id' => $this->tenant->getKey()]);
    $mine = CustomerPortalUser::factory()->create(['tenant_id' => $this->tenant->getKey()]);
    $mine->customers()->attach($brand->getKey(), [
        'tenant_id' => $this->tenant->getKey(),
        'role' => 'approver',
    ]);

    $this->actingAs($mine, 'customer')
        ->get('http://portal.rival.test/portal')
        ->assertNotFound();
});

it('still serves the portal on the platform hostname', function (): void {
    // Custom domains are additional, not a replacement. An agency that never
    // sets one must keep working.
    $this->get(route('portal.login'))->assertOk();
});
