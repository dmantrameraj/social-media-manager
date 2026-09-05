<?php

declare(strict_types=1);

namespace App\Domain\Social\Providers\Meta;

use App\Domain\Media\Services\ProviderMediaUrl;
use App\Domain\Social\Contracts\SocialProviderInterface;
use App\Domain\Social\DTO\CapabilitySet;
use App\Domain\Social\DTO\DiscoveredAccount;
use App\Domain\Social\DTO\MediaItem;
use App\Domain\Social\DTO\PublishPayload;
use App\Domain\Social\DTO\PublishResult;
use App\Domain\Social\DTO\TokenSet;
use App\Domain\Social\DTO\ValidationError;
use App\Domain\Social\DTO\ValidationResult;
use App\Domain\Social\Enums\ProviderErrorClass;
use App\Domain\Social\Enums\SocialAccountType;
use App\Domain\Social\Exceptions\ProviderException;
use App\Domain\Social\Models\SocialAccount;
use App\Domain\Social\Models\SocialConnection;
use App\Domain\Social\OAuth\OAuthContext;
use App\Domain\Social\ProviderRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Publishing to a Facebook Page.
 *
 * VERIFIED AGAINST developers.facebook.com, 2026-09-05, Graph API v25.0:
 *
 *   - dialog          GET  https://www.facebook.com/v25.0/dialog/oauth
 *   - code exchange   GET  /oauth/access_token (client_id, redirect_uri,
 *                          client_secret, code)
 *   - long-lived      GET  /oauth/access_token (grant_type=fb_exchange_token)
 *   - pages           GET  /{user-id}/accounts
 *   - text post       POST /{page-id}/feed        (message)
 *   - photo post      POST /{page-id}/photos      (url, caption)
 *
 * Nothing here was written from memory. §64 forbids it, and the reason is
 * specific to this file: a wrong field name in an adapter does not throw, it
 * publishes the wrong thing to a client's audience.
 *
 * WHAT IS NOT HERE, and must be verified before it is added: video and Reels
 * uploads (resumable, a different host), scheduled_publish_time (we schedule
 * ourselves, so native scheduling is deliberately unused), and Insights. Each
 * needs its own reading of the documentation.
 */
final class FacebookPageProvider implements SocialProviderInterface
{
    public function __construct(
        private readonly MetaGraphClient $graph,
        private readonly ProviderRegistry $registry,
        private readonly ProviderMediaUrl $mediaUrls,
    ) {}

    public function key(): string
    {
        return 'facebook';
    }

    public function capabilities(SocialAccount $account): CapabilitySet
    {
        /*
         | Resolved by the registry from config and narrowed by the scopes
         | actually granted -- not asserted here. A Page connected without
         | pages_manage_posts is not publish-capable whatever this class
         | believes, because the user can decline individual permissions.
         */
        $connection = $account->socialConnection;

        return $this->registry->resolveCapabilities(
            $this->key(),
            $account->account_type,
            $connection instanceof SocialConnection ? ($connection->scopes ?? []) : [],
        );
    }

    // ------------------------------------------------------------------ OAuth

    public function authorizationUrl(OAuthContext $context): string
    {
        if ($context->clientId === null) {
            throw new ProviderException(
                ProviderErrorClass::Permission,
                'No Facebook app is configured for this agency.',
            );
        }

        return $this->graph->dialogUrl().'?'.http_build_query([
            'client_id' => $context->clientId,
            // From configuration and exact-matched by Meta. Never from a
            // request: an arbitrary redirect_uri is an open-redirect and
            // token-interception primitive.
            'redirect_uri' => $context->redirectUri,
            'state' => $context->state,
            'response_type' => 'code',
            'scope' => implode(',', $context->scopes),
        ]);
    }

    public function exchangeCode(string $code, OAuthContext $context): TokenSet
    {
        $short = $this->graph->get('oauth/access_token', [
            'client_id' => $context->clientId,
            'client_secret' => $context->clientSecret,
            'redirect_uri' => $context->redirectUri,
            'code' => $code,
        ]);

        /*
         | Exchanged for a long-lived token immediately.
         |
         | The short-lived token Meta returns here lasts a couple of hours. A
         | connection that works this afternoon and is dead tomorrow is the
         | single most confusing failure this integration can produce, and the
         | exchange costs one request.
         */
        $long = $this->longLived((string) $short['access_token'], $context->clientId, $context->clientSecret);

        $me = $this->graph->get('me', [
            'fields' => 'id,name',
            'access_token' => $long['access_token'],
        ]);

        return new TokenSet(
            accessToken: (string) $long['access_token'],
            externalUserId: (string) $me['id'],
            // Meta has no refresh token: a long-lived token is re-exchanged
            // for another long-lived one, which refresh() below does.
            refreshToken: null,
            expiresAt: $this->expiryFrom($long),
            grantedScopes: $context->scopes,
            name: isset($me['name']) ? (string) $me['name'] : null,
        );
    }

    /**
     * Re-exchange a long-lived token for a fresh one.
     *
     * Meta issues no refresh token; the same fb_exchange_token grant accepts a
     * long-lived token and returns another with the clock reset. Doing this
     * before expiry is what keeps a connection alive without the user.
     */
    public function refresh(SocialConnection $connection): TokenSet
    {
        $credential = $connection->credential;

        if ($credential === null) {
            throw new ProviderException(
                ProviderErrorClass::Permission,
                'The developer app for this connection is no longer available.',
            );
        }

        $fresh = $this->longLived(
            (string) $connection->access_token,
            (string) $credential->client_id,
            (string) $credential->client_secret,
        );

        return new TokenSet(
            accessToken: (string) $fresh['access_token'],
            externalUserId: (string) $connection->external_user_id,
            expiresAt: $this->expiryFrom($fresh),
            grantedScopes: $connection->scopes ?? [],
        );
    }

    public function revoke(SocialConnection $connection): void
    {
        /*
         | DELETE /{user-id}/permissions revokes the whole grant. We do not
         | call it: the agency may hold several connections through the same
         | Facebook user, and revoking centrally would silently disconnect the
         | others. Removing our stored token ends OUR access, which is what
         | disconnecting a brand means; the person can revoke the app itself
         | from Facebook's own settings, which is the only place that decision
         | belongs.
         */
    }

    /**
     * The Pages this grant administers.
     *
     * @return Collection<int, DiscoveredAccount>
     */
    public function discoverAccounts(SocialConnection $connection): Collection
    {
        $response = $this->graph->get((string) $connection->external_user_id.'/accounts', [
            'fields' => 'id,name,username,access_token,tasks,picture{url}',
            'access_token' => (string) $connection->access_token,
        ]);

        return collect((array) ($response['data'] ?? []))
            /*
             | Only Pages this user can actually post to. Meta returns every
             | Page they have any role on, including ANALYZE-only, and offering
             | one of those as a destination produces a permission error at
             | publish time -- long after the choice was made.
             */
            ->filter(fn (array $page): bool => in_array('CREATE_CONTENT', (array) ($page['tasks'] ?? []), true))
            ->map(fn (array $page): DiscoveredAccount => new DiscoveredAccount(
                externalId: (string) $page['id'],
                name: (string) $page['name'],
                type: SocialAccountType::Page,
                username: isset($page['username']) ? (string) $page['username'] : null,
                avatarUrl: $page['picture']['data']['url'] ?? null,
                /*
                 | The PAGE token, not the user token. Publishing uses this,
                 | and unlike the user token it does not expire -- which is why
                 | DiscoveredAccount carries a field for it at all.
                 */
                pageAccessToken: (string) $page['access_token'],
                scopes: $connection->scopes ?? [],
                meta: ['tasks' => $page['tasks'] ?? []],
            ))
            ->values();
    }

    // ------------------------------------------------------------- publishing

    public function validate(PublishPayload $payload, SocialAccount $account): ValidationResult
    {
        $capabilities = $this->capabilities($account);
        $errors = [];

        if (trim($payload->body) === '' && $payload->media === []) {
            $errors[] = new ValidationError('body', 'empty', 'A Facebook post needs text or an image.');
        }

        $max = (int) $capabilities->limit('text_max');

        if ($max > 0 && mb_strlen($payload->body) > $max) {
            $errors[] = new ValidationError('body', 'text_too_long', "Facebook allows {$max} characters.");
        }

        $images = (int) $capabilities->limit('images_max');

        if ($images > 0 && count($payload->media) > $images) {
            $errors[] = new ValidationError('media', 'too_many_images', "Facebook allows {$images} images.");
        }

        /*
         | Video is refused rather than attempted. Uploading it is a resumable
         | flow against a different host, and that has not been verified
         | against the documentation -- so this says so plainly instead of
         | failing at publish time with something unrecognisable.
         */
        if ($payload->contentType === 'video') {
            $errors[] = new ValidationError('media', 'unsupported', 'Video posting to Facebook is not available yet.');
        }

        return $errors === [] ? ValidationResult::pass() : ValidationResult::fail($errors);
    }

    public function publish(PublishPayload $payload, SocialAccount $account): PublishResult
    {
        $token = $account->page_access_token;

        if ($token === null) {
            throw new ProviderException(
                ProviderErrorClass::AuthExpired,
                'This Page needs reconnecting before it can publish.',
            );
        }

        $pageId = (string) $account->external_id;

        // A photo post and a text post are different nodes, so the branch is
        // on what is attached rather than on a flag somebody set.
        $response = $payload->media === []
            ? $this->graph->post("{$pageId}/feed", array_filter([
                'message' => $payload->body,
                'link' => $payload->link,
                'access_token' => $token,
            ], static fn ($value): bool => $value !== null && $value !== ''))
            : $this->graph->post("{$pageId}/photos", [
                'url' => $this->publicUrl($payload->media[0]),
                'caption' => $payload->body,
                'access_token' => $token,
            ]);

        /*
         | A photo returns both `id` (the photo) and `post_id` (the post on the
         | feed). post_id is the one a person can open and the one deletion and
         | insights take, so it wins when present.
         */
        $externalId = (string) ($response['post_id'] ?? $response['id'] ?? '');

        if ($externalId === '') {
            throw new ProviderException(
                ProviderErrorClass::Unknown,
                'Facebook accepted the post but returned no id.',
                context: ['response' => $response],
            );
        }

        return new PublishResult(
            externalId: $externalId,
            externalUrl: "https://www.facebook.com/{$externalId}",
            publishedAt: Carbon::now(),
            raw: $response,
        );
    }

    /**
     * A URL Meta can actually fetch the image from.
     *
     * Meta pulls the file from its own network rather than accepting bytes, and
     * our media sits on a private disk. ProviderMediaUrl mints a short-lived
     * signed URL for exactly this, and refusing when one cannot be produced is
     * the point: sending a path Meta cannot read fails at their end, minutes
     * later, as something unrecognisable.
     */
    private function publicUrl(MediaItem $item): string
    {
        if (! $this->mediaUrls->isReachable()) {
            throw new ProviderException(
                ProviderErrorClass::Media,
                'Images cannot be published from this environment: the network has no route back to it.',
                providerCode: 'unreachable_host',
            );
        }

        $url = $this->mediaUrls->for($item);

        if ($url === null) {
            throw new ProviderException(
                ProviderErrorClass::Media,
                'That image is no longer available to publish.',
                providerCode: 'media_missing',
            );
        }

        return $url;
    }

    /**
     * @return array<string, mixed>
     */
    private function longLived(string $token, ?string $clientId, ?string $clientSecret): array
    {
        return $this->graph->get('oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'fb_exchange_token' => $token,
        ]);
    }

    /**
     * @param  array<string, mixed>  $token
     */
    private function expiryFrom(array $token): ?Carbon
    {
        $seconds = (int) ($token['expires_in'] ?? 0);

        // Meta omits expires_in for tokens that do not expire. Null is the
        // honest representation of that; a far-future date would be a guess
        // the refresh scheduler would act on.
        return $seconds > 0 ? Carbon::now()->addSeconds($seconds) : null;
    }
}
