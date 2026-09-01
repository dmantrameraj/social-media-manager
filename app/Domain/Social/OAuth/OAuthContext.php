<?php

declare(strict_types=1);

namespace App\Domain\Social\OAuth;

/**
 * Everything a provider needs to build an authorisation URL or exchange a code.
 *
 * The redirect URI comes from configuration and is exact-matched -- an
 * arbitrary redirect_uri from a request is never accepted, because that is an
 * open-redirect and token-interception primitive.
 */
final readonly class OAuthContext
{
    /** @param  list<string>  $scopes */
    public function __construct(
        public int $tenantId,
        public int $userId,
        public string $providerKey,
        public string $redirectUri,
        public string $state,
        public array $scopes = [],
        public ?string $codeVerifier = null,
        public ?string $clientId = null,
        public ?string $clientSecret = null,
        public ?int $customerId = null,
    ) {}

    public function usesPkce(): bool
    {
        return $this->codeVerifier !== null;
    }

    /** S256 challenge derived from the verifier. */
    public function codeChallenge(): ?string
    {
        if ($this->codeVerifier === null) {
            return null;
        }

        return rtrim(strtr(base64_encode(hash('sha256', $this->codeVerifier, true)), '+/', '-_'), '=');
    }
}
