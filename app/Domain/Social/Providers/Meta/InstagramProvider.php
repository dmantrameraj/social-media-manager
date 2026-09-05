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
 * Publishing to an Instagram Business account.
 *
 * VERIFIED AGAINST developers.facebook.com, 2026-09-05, Graph API v25.0:
 *
 *   - container    POST /{ig-id}/media          (image_url, caption,
 *                                                media_type, children)
 *   - status       GET  /{container-id}?fields=status_code
 *                       EXPIRED | ERROR | FINISHED | IN_PROGRESS | PUBLISHED
 *   - publish      POST /{ig-id}/media_publish  (creation_id)
 *   - discovery    GET  /{page-id}?fields=instagram_business_account
 *
 * TWO PHASES, AND WHY THAT IS SAFE TO RETRY
 *
 * Creating a container does not post anything; only media_publish does. So a
 * crash between the two leaves an unused container that Meta expires after 24
 * hours, and a retry creates a fresh one. That leaks a container and cannot
 * duplicate a post, which is the right way round.
 *
 * The remaining risk is the same one every provider has: media_publish
 * succeeds and the reply is lost. Recovering from that needs
 * SupportsRecentPostLookup, and the media-listing endpoint it would use has
 * NOT been verified here -- so it is deliberately not implemented rather than
 * guessed at. Until it is, a lost reply is retried and may double-post, which
 * is recorded here because it is exactly the sort of thing that should not be
 * discovered from a client's feed.
 *
 * OAuth is Facebook Login: an Instagram Business account is reached through
 * the Page that owns it, so the token flow is inherited rather than repeated.
 */
final class InstagramProvider implements SocialProviderInterface
{
    public function __construct(
        private readonly MetaGraphClient $graph,
        private readonly ProviderRegistry $registry,
        private readonly FacebookPageProvider $facebook,
        private readonly ProviderMediaUrl $mediaUrls,
    ) {}

    public function key(): string
    {
        return 'instagram';
    }

    public function capabilities(SocialAccount $account): CapabilitySet
    {
        $connection = $account->socialConnection;

        return $this->registry->resolveCapabilities(
            $this->key(),
            $account->account_type,
            $connection instanceof SocialConnection ? ($connection->scopes ?? []) : [],
        );
    }

    // ------------------------------------------------------------------ OAuth
    //
    // All four delegate: an Instagram Business account is administered through
    // the Facebook Page that owns it, so there is one grant, one token and one
    // refresh path. Duplicating them here would create a second thing to keep
    // correct that is meant to behave identically.

    public function authorizationUrl(OAuthContext $context): string
    {
        return $this->facebook->authorizationUrl($context);
    }

    public function exchangeCode(string $code, OAuthContext $context): TokenSet
    {
        return $this->facebook->exchangeCode($code, $context);
    }

    public function refresh(SocialConnection $connection): TokenSet
    {
        return $this->facebook->refresh($connection);
    }

    public function revoke(SocialConnection $connection): void
    {
        $this->facebook->revoke($connection);
    }

    /**
     * The Instagram Business accounts behind this grant's Pages.
     *
     * @return Collection<int, DiscoveredAccount>
     */
    public function discoverAccounts(SocialConnection $connection): Collection
    {
        $pages = $this->graph->get((string) $connection->external_user_id.'/accounts', [
            'fields' => 'id,name,access_token,instagram_business_account{id,username,name,profile_picture_url}',
            'access_token' => (string) $connection->access_token,
        ]);

        return collect((array) ($pages['data'] ?? []))
            // A Page without a linked professional account is not an Instagram
            // destination, and offering it would fail at publish time.
            ->filter(fn (array $page): bool => isset($page['instagram_business_account']['id']))
            ->map(function (array $page) use ($connection): DiscoveredAccount {
                $ig = (array) $page['instagram_business_account'];

                return new DiscoveredAccount(
                    externalId: (string) $ig['id'],
                    name: (string) ($ig['name'] ?? $ig['username'] ?? $page['name']),
                    type: SocialAccountType::IgBusiness,
                    username: isset($ig['username']) ? (string) $ig['username'] : null,
                    avatarUrl: $ig['profile_picture_url'] ?? null,
                    /*
                     | The PAGE token, not an Instagram one. Instagram
                     | publishing authenticates as the owning Page, which is
                     | the detail most likely to be got wrong here.
                     */
                    pageAccessToken: (string) $page['access_token'],
                    scopes: $connection->scopes ?? [],
                    meta: ['page_id' => $page['id']],
                );
            })
            ->values();
    }

    // ------------------------------------------------------------- publishing

    public function validate(PublishPayload $payload, SocialAccount $account): ValidationResult
    {
        $capabilities = $this->capabilities($account);
        $errors = [];

        /*
         | Instagram has no text-only post. This is the difference between the
         | two networks that most often surprises somebody composing for both
         | at once, so it is caught in the composer rather than at publish.
         */
        if ($payload->media === []) {
            $errors[] = new ValidationError('media', 'media_required', 'Instagram posts need an image or a video.');
        }

        $max = (int) $capabilities->limit('text_max');

        if ($max > 0 && mb_strlen($payload->body) > $max) {
            $errors[] = new ValidationError('body', 'text_too_long', "Instagram allows {$max} characters.");
        }

        if (count($payload->media) > 10) {
            // Meta's own carousel ceiling: "up to 10 container IDs".
            $errors[] = new ValidationError('media', 'too_many_images', 'A carousel holds at most 10 items.');
        }

        if ($payload->contentType === 'video') {
            // Reels upload is resumable against rupload.facebook.com and has
            // not been verified. Refused rather than attempted.
            $errors[] = new ValidationError('media', 'unsupported', 'Video posting to Instagram is not available yet.');
        }

        return $errors === [] ? ValidationResult::pass() : ValidationResult::fail($errors);
    }

    public function publish(PublishPayload $payload, SocialAccount $account): PublishResult
    {
        $token = $account->page_access_token;

        if ($token === null) {
            throw new ProviderException(
                ProviderErrorClass::AuthExpired,
                'This Instagram account needs reconnecting before it can publish.',
            );
        }

        $igId = (string) $account->external_id;

        $containerId = count($payload->media) > 1
            ? $this->carouselContainer($igId, $payload, $token)
            : $this->imageContainer($igId, $this->publicUrl($payload->media[0]), $payload->body, $token);

        $this->awaitContainer($containerId, $token);

        $published = $this->graph->post("{$igId}/media_publish", [
            'creation_id' => $containerId,
            'access_token' => $token,
        ]);

        $mediaId = (string) ($published['id'] ?? '');

        if ($mediaId === '') {
            throw new ProviderException(
                ProviderErrorClass::Unknown,
                'Instagram accepted the post but returned no id.',
                context: ['response' => $published],
            );
        }

        return new PublishResult(
            externalId: $mediaId,
            externalUrl: null,
            publishedAt: Carbon::now(),
            raw: $published + ['container_id' => $containerId],
        );
    }

    /**
     * A URL Instagram can actually fetch the image from.
     *
     * Instagram does not accept image bytes at all -- it takes image_url and
     * pulls the file itself. Our media is on a private disk, so this is the
     * one place a short-lived signed public URL is minted, and refusing when
     * one cannot be produced is deliberate: a URL Instagram cannot read fails
     * at their end as a container ERROR, minutes later, with no explanation.
     */
    private function publicUrl(MediaItem $item): string
    {
        if (! $this->mediaUrls->isReachable()) {
            throw new ProviderException(
                ProviderErrorClass::Media,
                'Images cannot be published from this environment: Instagram has no route back to it.',
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

    /** @return string the container id */
    private function imageContainer(string $igId, string $url, string $caption, string $token): string
    {
        $container = $this->graph->post("{$igId}/media", array_filter([
            'image_url' => $url,
            'caption' => $caption,
            'access_token' => $token,
        ], static fn ($value): bool => $value !== ''));

        return (string) ($container['id'] ?? '');
    }

    /**
     * A carousel: one container per item, then a parent that lists them.
     *
     * The children are created with is_carousel_item so Meta does not treat
     * each as a post of its own.
     */
    private function carouselContainer(string $igId, PublishPayload $payload, string $token): string
    {
        $children = [];

        foreach ($payload->media as $item) {
            $child = $this->graph->post("{$igId}/media", [
                'image_url' => $this->publicUrl($item),
                'is_carousel_item' => 'true',
                'access_token' => $token,
            ]);

            $children[] = (string) ($child['id'] ?? '');
        }

        $parent = $this->graph->post("{$igId}/media", array_filter([
            'media_type' => 'CAROUSEL',
            'children' => implode(',', $children),
            'caption' => $payload->body,
            'access_token' => $token,
        ], static fn ($value): bool => $value !== ''));

        return (string) ($parent['id'] ?? '');
    }

    /**
     * Wait for Meta to finish processing the container.
     *
     * Publishing one that is still IN_PROGRESS fails, so this polls -- bounded,
     * because a job that waits indefinitely on somebody else's queue is a
     * worker that never comes back. Exceeding the bound is retryable and does
     * not consume an attempt: the container is still there and the next run
     * may well find it finished.
     */
    private function awaitContainer(string $containerId, string $token): void
    {
        if ($containerId === '') {
            throw new ProviderException(
                ProviderErrorClass::Unknown,
                'Instagram did not return a container to publish.',
            );
        }

        $attempts = (int) config('social.meta.container_poll_attempts', 8);
        $wait = (int) config('social.meta.container_poll_seconds', 3);

        for ($i = 0; $i < $attempts; $i++) {
            $status = (string) ($this->graph->get($containerId, [
                'fields' => 'status_code',
                'access_token' => $token,
            ])['status_code'] ?? '');

            if ($status === 'FINISHED' || $status === 'PUBLISHED') {
                return;
            }

            if ($status === 'ERROR') {
                throw new ProviderException(
                    ProviderErrorClass::Media,
                    'Instagram could not process that image.',
                    providerCode: 'container_error',
                );
            }

            if ($status === 'EXPIRED') {
                // Containers last 24 hours. One that expired was created by a
                // run that never finished, and a fresh attempt is correct.
                throw new ProviderException(
                    ProviderErrorClass::Media,
                    'The upload expired before it was published.',
                    providerCode: 'container_expired',
                );
            }

            if ($i < $attempts - 1) {
                sleep($wait);
            }
        }

        throw new ProviderException(
            ProviderErrorClass::Timeout,
            'Instagram is still processing this image.',
            providerCode: 'container_in_progress',
        );
    }
}
