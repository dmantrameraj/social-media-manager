<?php

declare(strict_types=1);

namespace App\Domain\Social\Providers\Fake;

use App\Domain\Social\Contracts\SocialProviderInterface;
use App\Domain\Social\Contracts\SupportsDeletion;
use App\Domain\Social\Contracts\SupportsRecentPostLookup;
use App\Domain\Social\DTO\CapabilitySet;
use App\Domain\Social\DTO\DiscoveredAccount;
use App\Domain\Social\DTO\PublishPayload;
use App\Domain\Social\DTO\PublishResult;
use App\Domain\Social\DTO\TokenSet;
use App\Domain\Social\DTO\ValidationError;
use App\Domain\Social\DTO\ValidationResult;
use App\Domain\Social\Enums\ProviderErrorClass;
use App\Domain\Social\Exceptions\ProviderException;
use App\Domain\Social\Models\SocialAccount;
use App\Domain\Social\Models\SocialConnection;
use App\Domain\Social\OAuth\OAuthContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * An in-memory provider used to build and test the publishing engine.
 *
 * This is NOT a stand-in for a real network. It exists because the engine's
 * hardest behaviour -- claim locking, retry classification, idempotent
 * recovery after a crash -- must be provable without depending on a live API,
 * a developer app, or platform review. Real adapters are then held to the same
 * contract this one demonstrates.
 *
 * Registered only in non-production environments.
 */
final class FakeProvider implements SocialProviderInterface, SupportsDeletion, SupportsRecentPostLookup
{
    /** Queued outcomes, consumed one per publish() call. */
    private static array $scriptedOutcomes = [];

    /** externalId => fingerprint, so duplicate recovery can be exercised. */
    private static array $published = [];

    private static int $publishCallCount = 0;

    /**
     * What discoverAccounts() returns. Empty by default: a grant that
     * administers nothing publishable is a real outcome, not a broken fake.
     *
     * @var list<DiscoveredAccount>
     */
    private static array $discoverable = [];

    /** Set when discovery should fail rather than return a list. */
    private static ?string $discoveryError = null;

    public function key(): string
    {
        return 'fake';
    }

    // ------------------------------------------------------------ scripting

    public static function reset(): void
    {
        self::$scriptedOutcomes = [];
        self::$published = [];
        self::$publishCallCount = 0;
        self::$discoverable = [];
        self::$discoveryError = null;
    }

    /**
     * What the next discoverAccounts() returns.
     *
     * @param  list<DiscoveredAccount>  $accounts
     */
    public static function willDiscover(array $accounts): void
    {
        self::$discoverable = $accounts;
        self::$discoveryError = null;
    }

    /** Discovery fails -- the grant is fine, the listing call is not. */
    public static function willFailDiscovery(string $message = 'Discovery unavailable'): void
    {
        self::$discoveryError = $message;
    }

    /** Next publish() throws this. */
    public static function willFailWith(ProviderErrorClass $class, string $message = 'Scripted failure'): void
    {
        self::$scriptedOutcomes[] = new ProviderException($class, $message);
    }

    /** Next publish() succeeds with this external id. */
    public static function willSucceedWith(string $externalId): void
    {
        self::$scriptedOutcomes[] = $externalId;
    }

    /**
     * Simulate a worker dying AFTER the platform accepted the post: the caller
     * sees a failure, but the post exists. This is the scenario that produces
     * duplicates in naive engines.
     */
    public static function willAcceptThenCrash(string $externalId, string $fingerprint): void
    {
        self::$published[$externalId] = $fingerprint;
        self::$scriptedOutcomes[] = new ProviderException(
            ProviderErrorClass::Network,
            'Connection lost after the platform accepted the post',
        );
    }

    public static function publishCallCount(): int
    {
        return self::$publishCallCount;
    }

    /** @return array<string, string> */
    public static function publishedPosts(): array
    {
        return self::$published;
    }

    // ------------------------------------------------------------- interface

    public function capabilities(SocialAccount $account): CapabilitySet
    {
        return new CapabilitySet(
            features: [
                'text' => true, 'images' => true, 'carousel' => true,
                'video' => true, 'link' => true, 'first_comment' => true,
                'deletion' => true,
            ],
            limits: ['text_max' => 1000, 'images_max' => 4],
            grantedScopes: ['fake.publish'],
        );
    }

    public function authorizationUrl(OAuthContext $context): string
    {
        return 'https://fake.test/oauth/authorize?state='.urlencode($context->state);
    }

    public function exchangeCode(string $code, OAuthContext $context): TokenSet
    {
        return new TokenSet(
            accessToken: 'fake-access-'.Str::random(16),
            externalUserId: 'fake-user-1',
            refreshToken: 'fake-refresh-'.Str::random(16),
            expiresAt: now()->addHour(),
            grantedScopes: ['fake.publish'],
            name: 'Fake User',
        );
    }

    public function refresh(SocialConnection $connection): TokenSet
    {
        return new TokenSet(
            accessToken: 'fake-access-'.Str::random(16),
            externalUserId: $connection->external_user_id,
            refreshToken: 'fake-refresh-'.Str::random(16),
            expiresAt: now()->addHour(),
            grantedScopes: $connection->scopes ?? ['fake.publish'],
        );
    }

    public function revoke(SocialConnection $connection): void
    {
        // Nothing to call.
    }

    public function discoverAccounts(SocialConnection $connection): Collection
    {
        if (self::$discoveryError !== null) {
            throw new ProviderException(
                ProviderErrorClass::Network,
                self::$discoveryError,
            );
        }

        return collect(self::$discoverable);
    }

    public function validate(PublishPayload $payload, SocialAccount $account): ValidationResult
    {
        $errors = [];
        $max = (int) $this->capabilities($account)->limit('text_max');

        if (mb_strlen($payload->body) > $max) {
            $errors[] = new ValidationError('body', 'text_too_long', "Text exceeds {$max} characters.");
        }

        return $errors === [] ? ValidationResult::pass() : ValidationResult::fail($errors);
    }

    public function publish(PublishPayload $payload, SocialAccount $account): PublishResult
    {
        self::$publishCallCount++;

        $outcome = array_shift(self::$scriptedOutcomes);

        if ($outcome instanceof ProviderException) {
            throw $outcome;
        }

        $externalId = is_string($outcome) ? $outcome : 'fake-post-'.Str::random(12);

        self::$published[$externalId] = (string) $payload->idempotencyKey;

        return new PublishResult(
            externalId: $externalId,
            externalUrl: 'https://fake.test/p/'.$externalId,
            publishedAt: now(),
        );
    }

    public function deletePost(SocialAccount $account, string $externalId): void
    {
        unset(self::$published[$externalId]);
    }

    /**
     * The recovery path: after a crash, ask whether the post actually landed
     * instead of blindly retrying.
     */
    public function findRecentPostByFingerprint(SocialAccount $account, string $fingerprint): ?string
    {
        foreach (self::$published as $externalId => $storedFingerprint) {
            if (hash_equals($storedFingerprint, $fingerprint)) {
                return (string) $externalId;
            }
        }

        return null;
    }
}
