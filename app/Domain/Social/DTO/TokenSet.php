<?php

declare(strict_types=1);

namespace App\Domain\Social\DTO;

use Illuminate\Support\Carbon;

/**
 * Tokens returned by an OAuth exchange or refresh.
 *
 * grantedScopes are what the provider ACTUALLY granted, which is not
 * necessarily what we asked for -- users can decline individual scopes.
 */
final readonly class TokenSet
{
    /**
     * @param  list<string>  $grantedScopes
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $accessToken,
        public string $externalUserId,
        public ?string $refreshToken = null,
        public ?Carbon $expiresAt = null,
        public ?Carbon $refreshExpiresAt = null,
        public string $tokenType = 'Bearer',
        public array $grantedScopes = [],
        public ?string $name = null,
        public ?string $email = null,
        public array $raw = [],
    ) {}

    public function hasRefreshToken(): bool
    {
        return $this->refreshToken !== null && $this->refreshToken !== '';
    }

    public function expiresWithin(int $seconds): bool
    {
        return $this->expiresAt !== null
            && $this->expiresAt->lessThanOrEqualTo(now()->addSeconds($seconds));
    }
}
