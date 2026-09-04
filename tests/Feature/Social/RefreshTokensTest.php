<?php

declare(strict_types=1);

use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Social\Enums\AccountHealth;
use App\Domain\Social\Enums\ConnectionStatus;
use App\Domain\Social\Enums\ProviderErrorClass;
use App\Domain\Social\Models\SocialAccount;
use App\Domain\Social\Models\SocialConnection;
use App\Domain\Social\ProviderRegistry;
use App\Domain\Social\Providers\Fake\FakeProvider;
use App\Domain\Tenancy\Services\ProvisionTenantService;

/*
 | Renewing a grant before it expires.
 |
 | The same shape as the publishing dispatcher: scopeNeedingRefresh() was
 | written with no caller, refresh() is on the contract and implemented by
 | every adapter, and refresh_lead_time has been configured since the social
 | tables were created. Nothing ran the query, so a token reached its expiry
 | and publishing began failing with no path back but an agency noticing.
 */

beforeEach(function (): void {
    seedPermissions();
    FakeProvider::reset();
    app(ProviderRegistry::class)->register('fake', FakeProvider::class);

    $owner = User::factory()->create();
    $this->tenant = app(ProvisionTenantService::class)->execute($owner, 'Bright Digital');
    actingForTenant($this->tenant);

    $this->brand = Customer::factory()->create(['tenant_id' => $this->tenant->getKey()]);
});

/** A grant inside the refresh window. */
function expiringConnection(array $overrides = []): SocialConnection
{
    return SocialConnection::factory()->create(array_merge([
        'tenant_id' => test()->tenant->getKey(),
        'provider_key' => 'fake',
        'status' => ConnectionStatus::Active->value,
        'refresh_token' => 'refresh-me',
        // Inside the default 24h lead time, and not yet expired: the whole
        // point is acting BEFORE anything breaks.
        'expires_at' => now()->addHours(2),
    ], $overrides));
}

// -------------------------------------------------------------------- the job

it('renews a grant that is close to expiring', function (): void {
    $connection = expiringConnection();
    $before = $connection->access_token;

    $this->artisan('social:refresh-tokens')->assertSuccessful();

    $fresh = $connection->fresh();

    expect($fresh->access_token)->not->toBe($before)
        ->and($fresh->expires_at->isAfter(now()->addMinutes(30)))->toBeTrue()
        ->and($fresh->last_refreshed_at)->not->toBeNull()
        ->and($fresh->status)->toBe(ConnectionStatus::Active);
});

it('leaves a grant that is nowhere near expiring', function (): void {
    // Outside the lead time. Renewing early costs a provider call and, on
    // networks that roll the refresh token, an unnecessary chance to fail.
    $connection = expiringConnection(['expires_at' => now()->addDays(30)]);
    $before = $connection->access_token;

    $this->artisan('social:refresh-tokens')->assertSuccessful();

    expect($connection->fresh()->access_token)->toBe($before)
        ->and($connection->fresh()->last_refreshed_at)->toBeNull();
});

it('ignores a grant with no refresh token', function (): void {
    // Nothing to renew with. The scope excludes these so the sweep does not
    // spend a provider call proving it every hour.
    $connection = expiringConnection(['refresh_token' => null]);

    $this->artisan('social:refresh-tokens')->assertSuccessful();

    expect($connection->fresh()->last_refreshed_at)->toBeNull();
});

it('keeps the existing refresh token when the provider returns none', function (): void {
    /*
     | Some providers roll the refresh token and some return none, meaning
     | "keep using the one you have". Overwriting with null in the second case
     | would destroy the only thing that can ever renew this grant again.
     */
    $connection = expiringConnection(['refresh_token' => 'keep-me']);

    FakeProvider::willRefreshWithoutRefreshToken();

    $this->artisan('social:refresh-tokens')->assertSuccessful();

    expect($connection->fresh()->refresh_token)->toBe('keep-me')
        // The access token still rolled -- only the refresh token was absent.
        ->and($connection->fresh()->access_token)->not->toBe('keep-me');
});

// ------------------------------------------------------------- what failed it

it('asks for a reconnect when the provider rejects the grant', function (): void {
    $connection = expiringConnection();
    $account = SocialAccount::factory()->forCustomer($this->brand)->create([
        'social_connection_id' => $connection->getKey(),
        'provider_key' => 'fake',
        'health' => AccountHealth::Healthy->value,
    ]);

    FakeProvider::willFailRefreshWith(ProviderErrorClass::AuthExpired);

    $this->artisan('social:refresh-tokens')->assertSuccessful();

    expect($connection->fresh()->status)->toBe(ConnectionStatus::NeedsReconnect)
        /*
         | The destinations are marked too. Health is what the accounts screen
         | reads, and a grant needing re-authorisation while its accounts still
         | look healthy is how somebody schedules a week that cannot go out.
         */
        ->and($account->fresh()->health)->toBe(AccountHealth::Failed)
        ->and($account->fresh()->last_error_at)->not->toBeNull();
});

it('does not ask for a reconnect over a network blip', function (): void {
    /*
     | The distinction the whole command turns on. Telling an agency to
     | re-authorise because a provider had a bad minute trains them to ignore
     | the warning that matters.
     */
    $connection = expiringConnection();
    $account = SocialAccount::factory()->forCustomer($this->brand)->create([
        'social_connection_id' => $connection->getKey(),
        'provider_key' => 'fake',
        'health' => AccountHealth::Healthy->value,
    ]);

    FakeProvider::willFailRefreshWith(ProviderErrorClass::Network);

    $this->artisan('social:refresh-tokens')->assertSuccessful();

    expect($connection->fresh()->status)->toBe(ConnectionStatus::Active)
        ->and($account->fresh()->health)->toBe(AccountHealth::Healthy)
        // Recorded, so a connection failing every hour is diagnosable.
        ->and($connection->fresh()->last_error_code)->toBe(ProviderErrorClass::Network->value);
});

it('does not condemn a connection whose adapter is not registered', function (): void {
    /*
     | Production currently registers no real adapters. Marking every grant
     | NeedsReconnect because this deployment cannot reach the provider would
     | send agencies round a loop that cannot terminate.
     */
    $connection = expiringConnection(['provider_key' => 'not-registered']);

    $this->artisan('social:refresh-tokens')->assertSuccessful();

    expect($connection->fresh()->status)->toBe(ConnectionStatus::Active);
});

// ------------------------------------------------------------------ the sweep

it('renews across tenants in one pass', function (): void {
    // Expiry is cross-tenant by definition: every agency's tokens age on the
    // same clock, and a scheduled sweep has no request to scope it to one.
    $mine = expiringConnection();

    [$other] = provisionTenant('Rival Agency');
    $theirs = SocialConnection::factory()->create([
        'tenant_id' => $other->getKey(),
        'provider_key' => 'fake',
        'status' => ConnectionStatus::Active->value,
        'refresh_token' => 'refresh-me',
        'expires_at' => now()->addHours(2),
    ]);

    actingForTenant($this->tenant);

    $this->artisan('social:refresh-tokens')->assertSuccessful();

    /*
     | Read back across tenants deliberately: with this test's context set to
     | one agency, ->fresh() on the other's row returns null through the global
     | scope -- which would make this assertion pass for the wrong reason.
     */
    $theirsAfter = SocialConnection::query()
        ->acrossTenants()
        ->find($theirs->getKey());

    expect($mine->fresh()->last_refreshed_at)->not->toBeNull()
        ->and($theirsAfter->last_refreshed_at)->not->toBeNull();
});

it('changes nothing on a dry run', function (): void {
    $connection = expiringConnection();

    $this->artisan('social:refresh-tokens', ['--dry-run' => true])
        ->assertSuccessful();

    expect($connection->fresh()->last_refreshed_at)->toBeNull();
});

it('honours the batch limit', function (): void {
    // Bounded so a backlog cannot turn one tick into an hour of provider calls.
    expiringConnection(['expires_at' => now()->addHour()]);
    expiringConnection(['expires_at' => now()->addHours(3)]);

    $this->artisan('social:refresh-tokens', ['--limit' => 1])->assertSuccessful();

    // Ordered by expiry, so the most urgent is the one that got the slot.
    $refreshed = SocialConnection::query()
        ->whereNotNull('last_refreshed_at')
        ->get();

    expect($refreshed)->toHaveCount(1)
        ->and($refreshed->first()->expires_at->isBefore(now()->addHours(2)))->toBeTrue();
});
