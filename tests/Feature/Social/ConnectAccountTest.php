<?php

declare(strict_types=1);

use App\Domain\Billing\Entitlements\EntitlementResolver;
use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Publishing\Enums\PostStatus;
use App\Domain\Publishing\Enums\TargetStatus;
use App\Domain\Publishing\Models\Post;
use App\Domain\Publishing\Models\PostTarget;
use App\Domain\Social\DTO\DiscoveredAccount;
use App\Domain\Social\Enums\AccountStatus;
use App\Domain\Social\Enums\SocialAccountType;
use App\Domain\Social\Models\SocialAccount;
use App\Domain\Social\Models\SocialConnection;
use App\Domain\Social\OAuth\OAuthStateService;
use App\Domain\Social\ProviderRegistry;
use App\Domain\Social\Providers\Fake\FakeProvider;
use App\Domain\Tenancy\Services\ProvisionTenantService;
use Illuminate\Support\Facades\DB;

/*
 | Connecting an account, end to end.
 |
 | Everything underneath this was already built and unreachable:
 | OAuthStateService could issue and consume single-use state, the provider
 | contract could exchange a code and list destinations, and no route or
 | controller joined them. A publishable account could only be created by
 | inserting a row by hand, which made the whole product a demo.
 */

beforeEach(function (): void {
    seedPermissions();
    FakeProvider::reset();
    app(ProviderRegistry::class)->register('fake', FakeProvider::class);

    $owner = User::factory()->create();
    $this->tenant = app(ProvisionTenantService::class)->execute($owner, 'Bright Digital');
    $this->owner = $owner->fresh();
    actingForTenant($this->tenant);

    $this->brand = Customer::factory()->create(['tenant_id' => $this->tenant->getKey()]);
});

/**
 * A state value as leg one would have issued it.
 *
 * Returned rather than scraped out of the redirect, because the controller
 * hands the provider a URL built by the adapter and the test should not have
 * to know that adapter's URL shape.
 */
function issueStateFor(User $user, ?Customer $brand = null): string
{
    ['state' => $state] = app(OAuthStateService::class)->issue(
        tenantId: test()->tenant->getKey(),
        userId: $user->getKey(),
        providerKey: 'fake',
        scopes: ['fake.publish'],
        customerId: ($brand ?? test()->brand)->getKey(),
    );

    return $state;
}

/** @return list<DiscoveredAccount> */
function twoPages(): array
{
    return [
        new DiscoveredAccount(
            externalId: 'page-1',
            name: 'Bright Coffee',
            type: SocialAccountType::Page,
            pageAccessToken: 'page-token-1',
        ),
        new DiscoveredAccount(
            externalId: 'page-2',
            name: 'A Client I Also Administer',
            type: SocialAccountType::Page,
            pageAccessToken: 'page-token-2',
        ),
    ];
}

// --------------------------------------------------------------- leaving here

it('sends the user to the provider and issues state', function (): void {
    $response = $this->actingAs($this->owner)
        ->get(route('agency.social.connect', ['provider' => 'fake', 'customer' => $this->brand->getKey()]));

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toStartWith('https://fake.test/oauth/authorize');

    // Issued, and stored only as a hash -- a database read must not yield a
    // usable state value.
    $row = DB::table('oauth_states')->first();
    expect($row)->not->toBeNull()
        ->and($row->user_id)->toBe($this->owner->getKey())
        ->and($row->customer_id)->toBe($this->brand->getKey())
        ->and(strlen((string) $row->state_hash))->toBe(64);
});

it('refuses to start a connection without the permission', function (): void {
    $member = memberWithRole($this->tenant, 'Manager');

    $this->actingAs($member)
        ->get(route('agency.social.connect', ['provider' => 'fake', 'customer' => $this->brand->getKey()]))
        ->assertForbidden();

    expect(DB::table('oauth_states')->count())->toBe(0);
});

it('cannot start a connection for another agency brand', function (): void {
    /*
     | The tenant scope hides the row, so a foreign brand and a deleted one are
     | indistinguishable from outside -- which is the point. A 403 would confirm
     | the id exists.
     */
    [$other] = provisionTenant('Rival Agency');
    $foreign = Customer::factory()->create(['tenant_id' => $other->getKey()]);

    actingForTenant($this->tenant);

    $this->actingAs($this->owner)
        ->get(route('agency.social.connect', ['provider' => 'fake', 'customer' => $foreign->getKey()]))
        ->assertNotFound();

    expect(DB::table('oauth_states')->count())->toBe(0);
});

it('does not start a connection for an unknown provider', function (): void {
    $this->actingAs($this->owner)
        ->from(route('agency.social.index'))
        ->get(route('agency.social.connect', ['provider' => 'myspace', 'customer' => $this->brand->getKey()]))
        ->assertRedirect(route('agency.social.index'))
        ->assertSessionHas('error');
});

// --------------------------------------------------------------- coming back

it('stores the grant and asks which accounts to use', function (): void {
    $state = issueStateFor($this->owner);

    $this->actingAs($this->owner)
        ->get(route('agency.social.callback', ['provider' => 'fake', 'code' => 'auth-code', 'state' => $state]))
        ->assertRedirect(route('agency.social.choose', SocialConnection::query()->sole()));

    $connection = SocialConnection::query()->sole();

    expect($connection->tenant_id)->toBe($this->tenant->getKey())
        ->and($connection->external_user_id)->toBe('fake-user-1')
        // What the provider ACTUALLY granted, not what was asked for.
        ->and($connection->scopes)->toBe(['fake.publish']);

    /*
     | Nothing is publishable yet. One grant can carry a dozen Pages and
     | attaching them all is how a client's post lands on the wrong one.
     */
    expect(SocialAccount::query()->count())->toBe(0);
});

it('treats a declined consent as a decision, not a fault', function (): void {
    $this->actingAs($this->owner)
        ->get(route('agency.social.callback', ['provider' => 'fake', 'error' => 'access_denied']))
        ->assertRedirect(route('agency.social.index'))
        ->assertSessionHas('error');

    expect(SocialConnection::query()->count())->toBe(0);
});

it('rejects a replayed callback', function (): void {
    $state = issueStateFor($this->owner);
    $url = route('agency.social.callback', ['provider' => 'fake', 'code' => 'auth-code', 'state' => $state]);

    $this->actingAs($this->owner)->get($url)->assertRedirect();

    // Single use: the second delivery must not produce a second grant.
    $this->actingAs($this->owner)->get($url)
        ->assertRedirect(route('agency.social.index'))
        ->assertSessionHas('error');

    expect(SocialConnection::query()->count())->toBe(1);
});

it('rejects a state issued to somebody else', function (): void {
    /*
     | The forwarded-link case: the state is valid, the person is not.
     |
     | Agency Admin rather than a role without the permission, so this proves
     | the STATE BINDING and not merely the permission check -- someone who is
     | fully entitled to connect accounts still cannot finish a flow they did
     | not start.
     */
    $state = issueStateFor($this->owner);
    $other = memberWithRole($this->tenant, 'Agency Admin');

    $this->actingAs($other)
        ->get(route('agency.social.callback', ['provider' => 'fake', 'code' => 'auth-code', 'state' => $state]))
        ->assertRedirect(route('agency.social.index'))
        ->assertSessionHas('error');

    expect(SocialConnection::query()->count())->toBe(0);
});

it('refuses to finish a connection without the permission', function (): void {
    /*
     | Checked on the way back too. Binding proves who the person is, not
     | whether they may still connect -- a permission withdrawn while they sat
     | on the consent screen has to count.
     */
    $state = issueStateFor($this->owner);
    $member = memberWithRole($this->tenant, 'Manager');

    $this->actingAs($member)
        ->get(route('agency.social.callback', ['provider' => 'fake', 'code' => 'auth-code', 'state' => $state]))
        ->assertForbidden();

    expect(SocialConnection::query()->count())->toBe(0);
});

it('keeps the grant when the destination listing fails', function (): void {
    $state = issueStateFor($this->owner);
    FakeProvider::willFailDiscovery();

    $this->actingAs($this->owner)
        ->get(route('agency.social.callback', ['provider' => 'fake', 'code' => 'auth-code', 'state' => $state]));

    $connection = SocialConnection::query()->sole();

    $this->actingAs($this->owner)
        ->get(route('agency.social.choose', $connection))
        ->assertRedirect(route('agency.social.index'))
        ->assertSessionHas('error');

    // Losing the grant would mean authorising again for a listing hiccup.
    expect(SocialConnection::query()->count())->toBe(1);
});

// ------------------------------------------------------------ choosing what

it('lists what the grant can publish to', function (): void {
    FakeProvider::willDiscover(twoPages());
    $connection = SocialConnection::factory()->create([
        'tenant_id' => $this->tenant->getKey(),
        'provider_key' => 'fake',
    ]);

    $this->actingAs($this->owner)
        ->get(route('agency.social.choose', $connection))
        ->assertOk()
        ->assertSee('Bright Coffee')
        ->assertSee('A Client I Also Administer');
});

it('connects only the accounts that were ticked', function (): void {
    FakeProvider::willDiscover(twoPages());
    $connection = SocialConnection::factory()->create([
        'tenant_id' => $this->tenant->getKey(),
        'provider_key' => 'fake',
    ]);

    $this->actingAs($this->owner)
        ->post(route('agency.social.store', $connection), [
            'customer' => $this->brand->getKey(),
            'accounts' => ['page-1'],
        ])
        ->assertRedirect(route('agency.social.index'));

    $accounts = SocialAccount::query()->get();

    expect($accounts)->toHaveCount(1)
        ->and($accounts->first()->external_id)->toBe('page-1')
        ->and($accounts->first()->customer_id)->toBe($this->brand->getKey())
        // The PAGE token, not the user token that discovered it. Storing the
        // wrong one produces an account that looks healthy and fails at
        // publish time.
        ->and($accounts->first()->page_access_token)->toBe('page-token-1');
});

it('ignores an account the provider never offered', function (): void {
    /*
     | The submitted list is a set of ids from a form, so the names, types and
     | tokens are re-read from the provider rather than taken from the payload.
     | Without that, a crafted post could write whatever it liked into a row
     | publishing later treats as authoritative.
     */
    FakeProvider::willDiscover(twoPages());
    $connection = SocialConnection::factory()->create([
        'tenant_id' => $this->tenant->getKey(),
        'provider_key' => 'fake',
    ]);

    $this->actingAs($this->owner)
        ->post(route('agency.social.store', $connection), [
            'customer' => $this->brand->getKey(),
            'accounts' => ['page-1', 'page-i-just-made-up'],
        ]);

    expect(SocialAccount::query()->pluck('external_id')->all())->toBe(['page-1']);
});

it('cannot attach accounts to another agency brand', function (): void {
    FakeProvider::willDiscover(twoPages());
    [$other] = provisionTenant('Rival Agency');
    $foreign = Customer::factory()->create(['tenant_id' => $other->getKey()]);

    actingForTenant($this->tenant);
    $connection = SocialConnection::factory()->create([
        'tenant_id' => $this->tenant->getKey(),
        'provider_key' => 'fake',
    ]);

    $this->actingAs($this->owner)
        ->post(route('agency.social.store', $connection), [
            'customer' => $foreign->getKey(),
            'accounts' => ['page-1'],
        ])
        ->assertNotFound();

    expect(SocialAccount::query()->count())->toBe(0);
});

// -------------------------------------------------------------- disconnecting

it('disconnects without destroying what was published', function (): void {
    /*
     | post_targets.social_account_id cascades on delete, so deleting the row
     | would take every post ever published to that account with it -- the
     | record of what went where, which is what an agency gets asked about
     | months later. Disconnected already means "cannot publish, does not count
     | toward the plan limit".
     */
    $account = SocialAccount::factory()->forCustomer($this->brand)->create();
    $post = Post::factory()->forCustomer($this->brand)->status(PostStatus::Published)->create();
    $target = PostTarget::factory()->targeting($post, $account)->create([
        'status' => TargetStatus::Published,
        'external_post_id' => 'external-1',
    ]);

    $this->actingAs($this->owner)
        ->from(route('agency.social.index'))
        ->delete(route('agency.social.destroy', $account))
        ->assertRedirect(route('agency.social.index'));

    expect($account->fresh()->status)->toBe(AccountStatus::Disconnected)
        ->and($account->fresh()->status->canPublish())->toBeFalse()
        // "Disconnect sets status and nulls the tokens instead", per the
        // migration. A live page token on an account nobody publishes to is
        // just a credential waiting to leak.
        ->and($account->fresh()->page_access_token)->toBeNull()
        ->and($target->fresh())->not->toBeNull()
        ->and($target->fresh()->external_post_id)->toBe('external-1');
});

it('refuses to disconnect without the permission', function (): void {
    $account = SocialAccount::factory()->forCustomer($this->brand)->create();
    $member = memberWithRole($this->tenant, 'Manager');

    $this->actingAs($member)
        ->delete(route('agency.social.destroy', $account))
        ->assertForbidden();

    expect($account->fresh()->status)->toBe(AccountStatus::Active);
});

// --------------------------------------------------------------- plan limits

it('refuses to connect more accounts than the plan sells', function (): void {
    /*
     | social_accounts.max has been in config since Step 8 and was never once
     | enforced: connecting was unreachable AND EntitlementResolver returned a
     | hard-coded 0 for this usage. Both halves missing is why neither half
     | looked broken.
     */
    givePlanLimit($this->tenant->getKey(), 'social_accounts.max', 1);
    FakeProvider::willDiscover(twoPages());

    $connection = SocialConnection::factory()->create([
        'tenant_id' => $this->tenant->getKey(),
        'provider_key' => 'fake',
    ]);

    $this->actingAs($this->owner)
        ->from(route('agency.social.choose', $connection))
        ->post(route('agency.social.store', $connection), [
            'customer' => $this->brand->getKey(),
            'accounts' => ['page-1', 'page-2'],
        ])
        ->assertRedirect(route('agency.social.choose', $connection))
        ->assertSessionHas('error')
        // What the flash partial reads to offer the billing link.
        ->assertSessionHas('upgrade_prompt', true);

    // All or nothing: a partial write would leave them over the limit anyway.
    expect(SocialAccount::query()->count())->toBe(0);
});

it('does not charge a second seat for reconnecting an account', function (): void {
    // The common path. Charging for it would make re-authorising after an
    // expired token look like hitting the plan ceiling.
    givePlanLimit($this->tenant->getKey(), 'social_accounts.max', 1);
    FakeProvider::willDiscover(twoPages());

    $connection = SocialConnection::factory()->create([
        'tenant_id' => $this->tenant->getKey(),
        'provider_key' => 'fake',
    ]);

    $payload = [
        'customer' => $this->brand->getKey(),
        'accounts' => ['page-1'],
    ];

    $this->actingAs($this->owner)->post(route('agency.social.store', $connection), $payload);
    $this->actingAs($this->owner)
        ->post(route('agency.social.store', $connection), $payload)
        ->assertSessionMissing('upgrade_prompt');

    expect(SocialAccount::query()->count())->toBe(1);
});

it('frees the seat when an account is disconnected', function (): void {
    // countsTowardLimit() says a disconnected account is not a seat in use,
    // and the usage counter now agrees with it.
    givePlanLimit($this->tenant->getKey(), 'social_accounts.max', 1);
    $account = SocialAccount::factory()->forCustomer($this->brand)->create();

    expect(app(EntitlementResolver::class)
        ->currentUsage($this->tenant, 'social_accounts.max'))->toBe(1);

    $this->actingAs($this->owner)
        ->from(route('agency.social.index'))
        ->delete(route('agency.social.destroy', $account));

    expect(app(EntitlementResolver::class)
        ->currentUsage($this->tenant->fresh(), 'social_accounts.max'))->toBe(0);
});

// ---------------------------------------------------------------- the listing

it('lists connected accounts', function (): void {
    SocialAccount::factory()->forCustomer($this->brand)->create(['name' => 'Bright Coffee']);

    $this->actingAs($this->owner)
        ->get(route('agency.social.index'))
        ->assertOk()
        ->assertSee('Bright Coffee');
});
