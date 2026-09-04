<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Social\Models\SocialAppCredential;
use App\Domain\Social\Models\SocialConnection;
use App\Domain\Social\OAuth\OAuthStateService;
use App\Domain\Social\Services\ResolveAppCredentialService;
use App\Domain\Tenancy\Services\ProvisionTenantService;
use Illuminate\Support\Facades\DB;

/*
 | An agency's own developer apps.
 |
 | social_app_credentials has existed since Phase 2 with encrypted casts, a
 | $hidden list, a toSafeArray() projection and a permission -- and no way to
 | put a row in it. oauth_states even carries social_app_credential_id, and
 | OAuthStateService::issue() already accepted a $credentialId that nobody
 | passed. Bring-your-own credentials is listed in the overview as a
 | differentiator; it was a schema comment.
 |
 | These tests are mostly about what must NOT happen. §64: never expose API
 | secrets, never put them in logs, never show them to Super Admin.
 */

beforeEach(function (): void {
    seedPermissions();

    $this->owner = User::factory()->create();
    $this->tenant = app(ProvisionTenantService::class)->execute($this->owner, 'Bright Digital');
    $this->owner = $this->owner->fresh();

    actingForTenant($this->tenant);
});

/** The form payload for a new app. */
function credentialPayload(array $overrides = []): array
{
    return array_merge([
        'provider_key' => 'facebook',
        'label' => 'Our Meta app',
        'client_id' => 'client-id-1234567890',
        'client_secret' => 'super-secret-value-0987654321',
    ], $overrides);
}

it('stores an agency developer app', function (): void {
    asAgencyUser($this->owner)
        ->post(route('agency.social.credentials.store'), credentialPayload())
        ->assertRedirect();

    $credential = SocialAppCredential::query()->sole();

    expect($credential->label)->toBe('Our Meta app')
        ->and($credential->client_secret)->toBe('super-secret-value-0987654321')
        ->and($credential->is_active)->toBeTrue();
});

it('encrypts the secret at rest', function (): void {
    asAgencyUser($this->owner)
        ->post(route('agency.social.credentials.store'), credentialPayload());

    // Read around Eloquent: what is actually on disk must not be the secret.
    $row = DB::table('social_app_credentials')->sole();

    expect($row->client_secret)->not->toContain('super-secret-value')
        ->and($row->client_id)->not->toContain('client-id-1234');
});

it('never renders a secret back', function (): void {
    asAgencyUser($this->owner)
        ->post(route('agency.social.credentials.store'), credentialPayload());

    asAgencyUser($this->owner)
        ->get(route('agency.social.credentials'))
        ->assertOk()
        // Not the secret, not the id, not a masked version of either: a mask
        // still confirms a length.
        ->assertDontSee('super-secret-value')
        ->assertDontSee('client-id-1234')
        // What the screen IS for.
        ->assertSee('Our Meta app');
});

it('keeps secrets out of the audit log', function (): void {
    asAgencyUser($this->owner)
        ->post(route('agency.social.credentials.store'), credentialPayload());

    $entry = AuditLog::query()->where('action', 'social_credential.created')->sole();

    // An audit log holding secrets is a second copy of them, in the one table
    // designed to be read by people.
    expect(json_encode($entry->new_values))->not->toContain('super-secret-value')
        ->and(json_encode($entry->new_values))->not->toContain('client-id-1234')
        ->and($entry->new_values['label'])->toBe('Our Meta app');
});

it('treats an empty secret on update as unchanged', function (): void {
    asAgencyUser($this->owner)
        ->post(route('agency.social.credentials.store'), credentialPayload());

    $credential = SocialAppCredential::query()->sole();

    asAgencyUser($this->owner)
        ->put(route('agency.social.credentials.update', $credential), [
            'label' => 'Renamed app',
            'client_secret' => '',
        ])
        ->assertRedirect();

    // Somebody renaming an app should not have to re-type a secret they may
    // not have to hand -- and blanking it would break every future connection.
    expect($credential->refresh()->label)->toBe('Renamed app')
        ->and($credential->client_secret)->toBe('super-secret-value-0987654321');
});

it('marks an app unverified again when its secret changes', function (): void {
    asAgencyUser($this->owner)
        ->post(route('agency.social.credentials.store'), credentialPayload());

    $credential = SocialAppCredential::query()->sole();
    $credential->forceFill(['verified_at' => now()])->save();

    asAgencyUser($this->owner)
        ->put(route('agency.social.credentials.update', $credential), [
            'label' => 'Our Meta app',
            'client_secret' => 'a-brand-new-secret',
        ]);

    // A green tick against credentials nobody has ever used is worse than no
    // tick at all.
    expect($credential->refresh()->verified_at)->toBeNull()
        ->and($credential->client_secret)->toBe('a-brand-new-secret');
});

it('rejects a network the platform does not support', function (): void {
    asAgencyUser($this->owner)
        ->post(route('agency.social.credentials.store'), credentialPayload([
            'provider_key' => 'myspace',
        ]))
        ->assertSessionHasErrors('provider_key');

    expect(SocialAppCredential::query()->count())->toBe(0);
});

it('will not delete an app accounts are still connected through', function (): void {
    asAgencyUser($this->owner)
        ->post(route('agency.social.credentials.store'), credentialPayload());

    $credential = SocialAppCredential::query()->sole();
    $brand = Customer::factory()->create(['tenant_id' => $this->tenant->getKey()]);

    SocialConnection::factory()->create([
        'tenant_id' => $this->tenant->getKey(),
        'customer_id' => $brand->getKey(),
        'social_app_credential_id' => $credential->getKey(),
    ]);

    asAgencyUser($this->owner)
        ->delete(route('agency.social.credentials.destroy', $credential))
        ->assertSessionHas('error');

    /*
     | The FK is nullOnDelete, so deleting would not break a row -- it would
     | quietly detach live connections from the app that granted them, and the
     | failure would surface later as a refresh nobody can explain.
     */
    expect($credential->refresh()->trashed())->toBeFalse();
});

it('turns an app off without deleting it', function (): void {
    asAgencyUser($this->owner)
        ->post(route('agency.social.credentials.store'), credentialPayload());

    $credential = SocialAppCredential::query()->sole();

    asAgencyUser($this->owner)
        ->put(route('agency.social.credentials.toggle', $credential))
        ->assertRedirect();

    expect($credential->refresh()->is_active)->toBeFalse();
});

it('is refused to an agency admin', function (): void {
    /*
     | social_credentials.manage is in Agency Admin's `except` list in the role
     | catalogue. That is deliberate -- secrets are the owner's business -- and
     | this asserts the catalogue is actually enforced.
     */
    asAgencyUser(memberWithRole($this->tenant, 'Agency Admin'))
        ->get(route('agency.social.credentials'))
        ->assertForbidden();
});

it('is refused to a manager', function (): void {
    asAgencyUser(memberWithRole($this->tenant, 'Manager'))
        ->post(route('agency.social.credentials.store'), credentialPayload())
        ->assertForbidden();
});

it('cannot touch another agency app', function (): void {
    [$rival] = provisionTenant('Rival Agency');
    actingForTenant($rival);

    $theirs = SocialAppCredential::query()->make();
    $theirs->forceFill([
        'tenant_id' => $rival->getKey(),
        'provider_key' => 'facebook',
        'label' => 'Their app',
        'client_id' => 'x',
        'client_secret' => 'y',
        'is_active' => true,
    ])->save();

    actingForTenant($this->tenant);

    asAgencyUser($this->owner)
        ->delete(route('agency.social.credentials.destroy', $theirs))
        ->assertNotFound();
});

// ------------------------------------------------------------------ resolution

it('resolves the agency own app for a network', function (): void {
    asAgencyUser($this->owner)
        ->post(route('agency.social.credentials.store'), credentialPayload());

    $resolved = app(ResolveAppCredentialService::class)
        ->for($this->tenant->getKey(), 'facebook');

    expect($resolved?->label)->toBe('Our Meta app');
});

it('falls back to the platform app when the agency has none', function (): void {
    // Null is a real answer, not a failure: it is why a new tenant can connect
    // anything at all.
    expect(app(ResolveAppCredentialService::class)->for($this->tenant->getKey(), 'facebook'))
        ->toBeNull();
});

it('ignores an app that has been turned off', function (): void {
    asAgencyUser($this->owner)
        ->post(route('agency.social.credentials.store'), credentialPayload());

    SocialAppCredential::query()->sole()->forceFill(['is_active' => false])->save();

    expect(app(ResolveAppCredentialService::class)->for($this->tenant->getKey(), 'facebook'))
        ->toBeNull();
});

it('does not resolve another agency app', function (): void {
    [$rival] = provisionTenant('Rival Agency');
    actingForTenant($rival);

    $theirs = SocialAppCredential::query()->make();
    $theirs->forceFill([
        'tenant_id' => $rival->getKey(),
        'provider_key' => 'facebook',
        'label' => 'Their app',
        'client_id' => 'x',
        'client_secret' => 'y',
        'is_active' => true,
    ])->save();

    actingForTenant($this->tenant);

    // Connecting a client's account through another agency's app would hand
    // them the grant.
    expect(app(ResolveAppCredentialService::class)->for($this->tenant->getKey(), 'facebook'))
        ->toBeNull();
});

// ------------------------------------------------------ carried through OAuth

/** A credential created directly, for a provider key the registry knows. */
function credentialFor(string $providerKey = 'facebook', array $overrides = []): SocialAppCredential
{
    $credential = new SocialAppCredential;

    $credential->forceFill(array_merge([
        'tenant_id' => test()->tenant->getKey(),
        'provider_key' => $providerKey,
        'label' => 'Our app',
        'client_id' => 'agency-client-id',
        'client_secret' => 'agency-client-secret',
        'is_active' => true,
    ], $overrides))->save();

    return $credential;
}

it('carries the agency app into the authorisation request', function (): void {
    /*
     | The wire that was missing. oauth_states.social_app_credential_id and
     | OAuthContext::$clientId both existed; nothing put anything in either, so
     | every grant ran on the platform's app whatever the agency had stored.
     */
    $credential = credentialFor();

    ['context' => $context] = app(OAuthStateService::class)->issue(
        tenantId: $this->tenant->getKey(),
        userId: $this->owner->getKey(),
        providerKey: 'facebook',
        credentialId: $credential->getKey(),
    );

    expect($context->clientId)->toBe('agency-client-id')
        ->and($context->clientSecret)->toBe('agency-client-secret')
        ->and(DB::table('oauth_states')->value('social_app_credential_id'))
        ->toBe($credential->getKey());
});

it('exchanges the code against the same app that issued the grant', function (): void {
    /*
     | Read back off the state row rather than resolved again, so an agency
     | that changes their credentials while a user is away at the provider does
     | not get a code exchanged against an app that never issued it.
     */
    $credential = credentialFor();

    ['state' => $state] = app(OAuthStateService::class)->issue(
        tenantId: $this->tenant->getKey(),
        userId: $this->owner->getKey(),
        providerKey: 'facebook',
        credentialId: $credential->getKey(),
    );

    $credential->forceFill(['client_secret' => 'rotated-since'])->save();

    $context = app(OAuthStateService::class)
        ->consume($state, 'facebook', $this->owner->getKey());

    expect($context->clientId)->toBe('agency-client-id')
        // The current stored value, which is the point: the SAME app row.
        ->and($context->clientSecret)->toBe('rotated-since');
});

it('falls back to the platform app when the agency app is turned off mid-flow', function (): void {
    $credential = credentialFor();

    ['state' => $state] = app(OAuthStateService::class)->issue(
        tenantId: $this->tenant->getKey(),
        userId: $this->owner->getKey(),
        providerKey: 'facebook',
        credentialId: $credential->getKey(),
    );

    $credential->forceFill(['is_active' => false])->save();

    $context = app(OAuthStateService::class)
        ->consume($state, 'facebook', $this->owner->getKey());

    // Null rather than a stale secret: an app the agency has switched off is
    // not one we should keep using on their behalf.
    expect($context->clientId)->toBeNull()
        ->and($context->clientSecret)->toBeNull();
});

it('sends no credential at all when the agency has none', function (): void {
    ['context' => $context] = app(OAuthStateService::class)->issue(
        tenantId: $this->tenant->getKey(),
        userId: $this->owner->getKey(),
        providerKey: 'facebook',
    );

    expect($context->clientId)->toBeNull()
        ->and(DB::table('oauth_states')->value('social_app_credential_id'))->toBeNull();
});
