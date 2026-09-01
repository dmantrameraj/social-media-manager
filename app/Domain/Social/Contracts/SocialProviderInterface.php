<?php

declare(strict_types=1);

namespace App\Domain\Social\Contracts;

use App\Domain\Social\DTO\CapabilitySet;
use App\Domain\Social\DTO\DiscoveredAccount;
use App\Domain\Social\DTO\PublishPayload;
use App\Domain\Social\DTO\PublishResult;
use App\Domain\Social\DTO\TokenSet;
use App\Domain\Social\DTO\ValidationResult;
use App\Domain\Social\Models\SocialAccount;
use App\Domain\Social\Models\SocialConnection;
use App\Domain\Social\OAuth\OAuthContext;
use Illuminate\Support\Collection;

/**
 * The minimum every provider must implement.
 *
 * Deliberately small. A single fat interface would force every provider to
 * implement methods it cannot support -- X has no Stories, YouTube has no
 * carousel, LinkedIn has no Reels -- producing a codebase full of methods that
 * throw. Anything not universal lives in a capability interface instead
 * (SupportsDeletion, SupportsAnalytics, ...), checked with instanceof.
 *
 * See docs/05-SOCIAL-PROVIDERS.md §2.
 */
interface SocialProviderInterface
{
    /** Registry key: 'facebook', 'instagram', 'linkedin', 'x', 'youtube'. */
    public function key(): string;

    /**
     * What this specific account can do, after narrowing the configured
     * capabilities by the scopes actually granted.
     */
    public function capabilities(SocialAccount $account): CapabilitySet;

    // ------------------------------------------------------------------ OAuth

    public function authorizationUrl(OAuthContext $context): string;

    public function exchangeCode(string $code, OAuthContext $context): TokenSet;

    public function refresh(SocialConnection $connection): TokenSet;

    public function revoke(SocialConnection $connection): void;

    /**
     * Publishable destinations behind this grant.
     *
     * @return Collection<int, DiscoveredAccount>
     */
    public function discoverAccounts(SocialConnection $connection): Collection;

    // ------------------------------------------------------------- publishing

    /**
     * Check a payload against this provider's rules WITHOUT calling the API.
     *
     * Must be pure and cheap: the engine runs it three times -- live in the
     * composer, on submit, and again immediately before publishing.
     */
    public function validate(PublishPayload $payload, SocialAccount $account): ValidationResult;

    /**
     * Publish, or throw a ProviderException carrying a mapped error class.
     *
     * Implementations must be safe to retry: where publishing is multi-phase,
     * the resumable handle is persisted so a retry resumes rather than
     * creating a second post. See docs/05-SOCIAL-PROVIDERS.md §10.
     */
    public function publish(PublishPayload $payload, SocialAccount $account): PublishResult;
}
