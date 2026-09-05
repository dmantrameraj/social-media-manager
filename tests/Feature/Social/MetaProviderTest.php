<?php

declare(strict_types=1);

use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Media\Models\Media;
use App\Domain\Social\DTO\MediaItem;
use App\Domain\Social\DTO\PublishPayload;
use App\Domain\Social\Enums\ProviderErrorClass;
use App\Domain\Social\Enums\SocialAccountType;
use App\Domain\Social\Exceptions\ProviderException;
use App\Domain\Social\Models\SocialAccount;
use App\Domain\Social\Models\SocialConnection;
use App\Domain\Social\OAuth\OAuthContext;
use App\Domain\Social\ProviderRegistry;
use App\Domain\Social\Providers\Meta\FacebookPageProvider;
use App\Domain\Social\Providers\Meta\InstagramProvider;
use App\Domain\Tenancy\Services\ProvisionTenantService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 | The Meta adapters.
 |
 | Every endpoint, parameter and error code these assert was read from
 | developers.facebook.com on 2026-09-05 (Graph API v25.0), not recalled. §64
 | forbids the alternative, and the reason is specific to an adapter: a wrong
 | field name does not throw. It publishes the wrong thing, or records a metric
 | that was never measured, and looks entirely normal doing it.
 |
 | Http::fake() throughout. These tests prove the SHAPE of what we send and how
 | we read a reply; they cannot prove Meta agrees, and no test on this machine
 | can. That needs a developer app and a real Page.
 */

beforeEach(function (): void {
    seedPermissions();
    Http::preventStrayRequests();

    /*
     | A reachable host. ProviderMediaUrl refuses localhost deliberately -- a
     | signed URL Meta cannot fetch is valid and useless -- so the suite states
     | a real one rather than disabling the check it is relying on.
     */
    config()->set('app.url', 'https://agency.example.com');

    $owner = User::factory()->create();
    $this->tenant = app(ProvisionTenantService::class)->execute($owner, 'Bright Digital');
    $this->owner = $owner->fresh();
    actingForTenant($this->tenant);

    $this->brand = Customer::factory()->create(['tenant_id' => $this->tenant->getKey()]);
    $this->facebook = app(FacebookPageProvider::class);
    $this->instagram = app(InstagramProvider::class);
});

function metaContext(array $overrides = []): OAuthContext
{
    return new OAuthContext(
        tenantId: test()->tenant->getKey(),
        userId: test()->owner->getKey(),
        providerKey: $overrides['provider'] ?? 'facebook',
        redirectUri: 'https://app.test/oauth/facebook/callback',
        state: 'state-token',
        scopes: ['pages_manage_posts', 'pages_manage_engagement'],
        clientId: '1234567890',
        clientSecret: 'app-secret',
    );
}

/** A Page ready to publish through. */
function metaAccount(string $provider = 'facebook', array $overrides = []): SocialAccount
{
    $connection = SocialConnection::factory()->create([
        'tenant_id' => test()->tenant->getKey(),
        'customer_id' => test()->brand->getKey(),
        'provider_key' => $provider,
        'external_user_id' => 'user-1',
        'access_token' => 'user-token',
        'scopes' => $provider === 'facebook'
            ? ['pages_manage_posts', 'pages_manage_engagement', 'pages_read_engagement']
            : ['instagram_basic', 'instagram_content_publish'],
    ]);

    return SocialAccount::factory()->forCustomer(test()->brand)->create(array_merge([
        'social_connection_id' => $connection->getKey(),
        'provider_key' => $provider,
        'account_type' => $provider === 'facebook'
            ? SocialAccountType::Page->value
            : SocialAccountType::IgBusiness->value,
        'external_id' => 'page-1',
        'page_access_token' => 'page-token',
    ], $overrides));
}

/**
 * An image the provider can be handed.
 *
 * A real Media row, because publishing now mints a short-lived signed URL for
 * the provider to fetch from -- Meta pulls the file itself rather than taking
 * bytes, and our disk is private.
 */
function metaImage(): MediaItem
{
    $media = Media::factory()->forCustomer(test()->brand)->create();

    return new MediaItem(
        id: $media->getKey(),
        path: $media->path,
        disk: $media->disk,
        mimeType: 'image/jpeg',
        sizeBytes: 1024,
    );
}

// ------------------------------------------------------------------ OAuth

it('sends the user to the documented authorisation dialog', function (): void {
    $url = $this->facebook->authorizationUrl(metaContext());

    // VERIFIED: https://www.facebook.com/v25.0/dialog/oauth
    expect($url)->toStartWith('https://www.facebook.com/v25.0/dialog/oauth?')
        ->and($url)->toContain('client_id=1234567890')
        ->and($url)->toContain('response_type=code')
        ->and($url)->toContain('state=state-token')
        // The redirect comes from configuration and is exact-matched by Meta.
        ->and($url)->toContain(urlencode('https://app.test/oauth/facebook/callback'));
});

it('refuses to build a dialog with no configured app', function (): void {
    $context = new OAuthContext(
        tenantId: $this->tenant->getKey(),
        userId: $this->owner->getKey(),
        providerKey: 'facebook',
        redirectUri: 'https://app.test/cb',
        state: 's',
    );

    $this->facebook->authorizationUrl($context);
})->throws(ProviderException::class);

it('exchanges the code and immediately upgrades to a long-lived token', function (): void {
    /*
     | The short-lived token Meta returns from a code exchange lasts hours. A
     | connection that works this afternoon and is dead tomorrow is the most
     | confusing failure this integration can produce, so the upgrade is not
     | optional and this asserts it happens.
     */
    Http::fake([
        '*oauth/access_token*fb_exchange_token*' => Http::response([
            'access_token' => 'long-lived-token', 'expires_in' => 5183944,
        ]),
        '*oauth/access_token*' => Http::response(['access_token' => 'short-token', 'expires_in' => 3600]),
        '*/me*' => Http::response(['id' => 'user-9', 'name' => 'Demo Owner']),
    ]);

    $tokens = $this->facebook->exchangeCode('auth-code', metaContext());

    expect($tokens->accessToken)->toBe('long-lived-token')
        ->and($tokens->externalUserId)->toBe('user-9')
        // Meta issues no refresh token; refresh() re-exchanges instead.
        ->and($tokens->refreshToken)->toBeNull()
        ->and($tokens->expiresAt)->not->toBeNull();

    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'grant_type=fb_exchange_token'));
});

it('lists only Pages the person may actually post to', function (): void {
    /*
     | Meta returns every Page the user has any role on, including
     | ANALYZE-only. Offering one of those as a destination produces a
     | permission error at publish time, long after the choice was made -- so
     | CREATE_CONTENT is required here, not merely the granted scope.
     */
    Http::fake(['*' => Http::response(['data' => [
        ['id' => 'p1', 'name' => 'Roast House', 'access_token' => 'tok1', 'tasks' => ['CREATE_CONTENT', 'MANAGE']],
        ['id' => 'p2', 'name' => 'Read Only Page', 'access_token' => 'tok2', 'tasks' => ['ANALYZE']],
    ]])]);

    $connection = SocialConnection::factory()->create([
        'tenant_id' => $this->tenant->getKey(),
        'customer_id' => $this->brand->getKey(),
        'provider_key' => 'facebook',
        'external_user_id' => 'user-1',
        'access_token' => 'user-token',
    ]);

    $found = $this->facebook->discoverAccounts($connection);

    expect($found)->toHaveCount(1)
        ->and($found->first()->externalId)->toBe('p1')
        // The PAGE token, not the user token: publishing uses this one, and it
        // does not expire.
        ->and($found->first()->pageAccessToken)->toBe('tok1');
});

// ------------------------------------------------------------- publishing

it('publishes text to the Page feed', function (): void {
    Http::fake(['*' => Http::response(['id' => 'page-1_9999'])]);

    $result = $this->facebook->publish(
        new PublishPayload(body: 'Hello from the agency.', contentType: 'text'),
        metaAccount(),
    );

    expect($result->externalId)->toBe('page-1_9999');

    // VERIFIED: POST /{page-id}/feed with `message`.
    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/v25.0/page-1/feed')
        && $r['message'] === 'Hello from the agency.'
        && $r['access_token'] === 'page-token');
});

it('publishes an image to the Page photos node', function (): void {
    // VERIFIED: a photo is a different node and returns BOTH ids. post_id is
    // the one a person can open, so it must win.
    Http::fake(['*' => Http::response(['id' => 'photo-1', 'post_id' => 'page-1_5555'])]);

    $result = $this->facebook->publish(
        new PublishPayload(
            body: 'Our new blend.',
            contentType: 'image',
            media: [metaImage()],
        ),
        metaAccount(),
    );

    expect($result->externalId)->toBe('page-1_5555');

    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/v25.0/page-1/photos')
        // Signed and per-request, so the assertion is on its shape: it must
        // point at our own provider-fetch route and carry a signature.
        && str_contains((string) $r['url'], '/m/')
        && str_contains((string) $r['url'], 'signature=')
        && $r['caption'] === 'Our new blend.');
});

it('will not publish through a Page that has no token', function (): void {
    Http::fake();

    $this->facebook->publish(
        new PublishPayload(body: 'x', contentType: 'text'),
        metaAccount(overrides: ['page_access_token' => null]),
    );
})->throws(ProviderException::class);

// --------------------------------------------------------- error taxonomy

it('maps a Meta error onto our own taxonomy', function (int $code, int $subcode, ProviderErrorClass $expected): void {
    /*
     | The engine decides retry, attempt consumption and reconnect from
     | ProviderErrorClass alone and must never see a Meta subcode. Codes are
     | from Meta's error-handling reference.
     */
    Http::fake(['*' => Http::response([
        'error' => [
            'message' => 'Something went wrong',
            'code' => $code,
            'error_subcode' => $subcode,
            'fbtrace_id' => 'trace-1',
        ],
    ], 400)]);

    try {
        $this->facebook->publish(new PublishPayload(body: 'x', contentType: 'text'), metaAccount());
        expect(false)->toBeTrue('Expected a ProviderException.');
    } catch (ProviderException $e) {
        expect($e->errorClass)->toBe($expected);
    }
})->with([
    'too many calls' => [4, 0, ProviderErrorClass::RateLimit],
    'user too many calls' => [17, 0, ProviderErrorClass::RateLimit],
    'app limit reached' => [341, 0, ProviderErrorClass::RateLimit],
    'token expired' => [190, 463, ProviderErrorClass::AuthExpired],
    'token invalid' => [190, 467, ProviderErrorClass::AuthExpired],
    'permission denied' => [10, 0, ProviderErrorClass::Permission],
    'permission block' => [200, 0, ProviderErrorClass::Permission],
    'duplicate post' => [506, 0, ProviderErrorClass::Duplicate],
    'invalid parameter' => [100, 0, ProviderErrorClass::Validation],
]);

it('prefers the message Meta wrote for the end user', function (): void {
    /*
     | `message` is written for developers and frequently names internal
     | fields. error_user_msg is Meta's own wording for a person, and is the
     | only one of the two safe to show somebody.
     */
    Http::fake(['*' => Http::response([
        'error' => [
            'message' => 'Invalid parameter: og_action_type_id',
            'error_user_msg' => 'That image is too small to post.',
            'code' => 100,
        ],
    ], 400)]);

    try {
        $this->facebook->publish(new PublishPayload(body: 'x', contentType: 'text'), metaAccount());
    } catch (ProviderException $e) {
        expect($e->getMessage())->toBe('That image is too small to post.');
    }
});

it('treats an unreachable network as retryable', function (): void {
    // Nothing was published, so retrying cannot duplicate anything -- which is
    // why this must not consume an attempt.
    Http::fake(fn () => throw new ConnectionException('down'));

    try {
        $this->facebook->publish(new PublishPayload(body: 'x', contentType: 'text'), metaAccount());
    } catch (ProviderException $e) {
        expect($e->errorClass)->toBe(ProviderErrorClass::Network)
            ->and($e->isRetryable())->toBeTrue();
    }
});

// -------------------------------------------------------------- Instagram

it('publishes an image in two phases', function (): void {
    /*
     | VERIFIED: POST /{ig-id}/media returns a container, then POST
     | /{ig-id}/media_publish with creation_id posts it. Only the second call
     | publishes anything, which is what makes a retry between them safe.
     */
    Http::fake([
        '*/media_publish*' => Http::response(['id' => 'ig-media-1']),
        '*/media*' => Http::response(['id' => 'container-1']),
        '*container-1*' => Http::response(['status_code' => 'FINISHED']),
    ]);

    $result = $this->instagram->publish(
        new PublishPayload(
            body: 'Morning light.',
            contentType: 'image',
            media: [metaImage()],
        ),
        metaAccount('instagram'),
    );

    expect($result->externalId)->toBe('ig-media-1')
        // The container id is kept on the result, so a lost reply can be
        // investigated against Meta's own logs.
        ->and($result->raw['container_id'])->toBe('container-1');

    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/media_publish')
        && $r['creation_id'] === 'container-1');
});

it('refuses a text-only Instagram post before calling anything', function (): void {
    Http::fake();

    $result = $this->instagram->validate(
        new PublishPayload(body: 'Just words.', contentType: 'text'),
        metaAccount('instagram'),
    );

    // Caught in the composer, not at publish time: this is the difference
    // between the two networks that most often surprises somebody.
    expect($result->failed())->toBeTrue();
    Http::assertNothingSent();
});

it('gives up on a container that never finishes', function (): void {
    config()->set('social.meta.container_poll_attempts', 2);
    config()->set('social.meta.container_poll_seconds', 0);

    Http::fake([
        '*/media_publish*' => Http::response(['id' => 'should-not-get-here']),
        '*/media*' => Http::response(['id' => 'container-1']),
        '*container-1*' => Http::response(['status_code' => 'IN_PROGRESS']),
    ]);

    try {
        $this->instagram->publish(
            new PublishPayload(body: 'x', contentType: 'image', media: [metaImage()]),
            metaAccount('instagram'),
        );
        expect(false)->toBeTrue('Expected a timeout.');
    } catch (ProviderException $e) {
        // Retryable: the container is still there and may well be ready next
        // time. A worker that waits for ever is a worker that never returns.
        expect($e->errorClass)->toBe(ProviderErrorClass::Timeout);
    }

    Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/media_publish'));
});

it('does not publish a container Meta could not process', function (): void {
    Http::fake([
        '*/media_publish*' => Http::response(['id' => 'should-not-get-here']),
        '*/media*' => Http::response(['id' => 'container-1']),
        '*container-1*' => Http::response(['status_code' => 'ERROR']),
    ]);

    try {
        $this->instagram->publish(
            new PublishPayload(body: 'x', contentType: 'image', media: [metaImage()]),
            metaAccount('instagram'),
        );
    } catch (ProviderException $e) {
        expect($e->errorClass)->toBe(ProviderErrorClass::Media);
    }

    Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/media_publish'));
});

it('finds Instagram accounts through the Pages that own them', function (): void {
    Http::fake(['*' => Http::response(['data' => [
        [
            'id' => 'p1', 'name' => 'Roast House', 'access_token' => 'tok1',
            'instagram_business_account' => ['id' => 'ig1', 'username' => 'roasthouse'],
        ],
        ['id' => 'p2', 'name' => 'No Instagram Here', 'access_token' => 'tok2'],
    ]])]);

    $connection = SocialConnection::factory()->create([
        'tenant_id' => $this->tenant->getKey(),
        'customer_id' => $this->brand->getKey(),
        'provider_key' => 'instagram',
        'external_user_id' => 'user-1',
        'access_token' => 'user-token',
    ]);

    $found = $this->instagram->discoverAccounts($connection);

    expect($found)->toHaveCount(1)
        ->and($found->first()->externalId)->toBe('ig1')
        // The PAGE token again: Instagram publishing authenticates as the
        // owning Page, which is the detail most easily got wrong.
        ->and($found->first()->pageAccessToken)->toBe('tok1');
});

it('is registered in production, not only in tests', function (): void {
    /*
     | The point of the whole exercise. Until these were registered the
     | registry held a fake outside production and nothing at all inside it,
     | so the product could not publish to a single network however complete
     | everything above the adapter was.
     */
    $registry = app(ProviderRegistry::class);

    expect($registry->has('facebook'))->toBeTrue()
        ->and($registry->has('instagram'))->toBeTrue()
        ->and($registry->for('facebook'))->toBeInstanceOf(FacebookPageProvider::class);
});
