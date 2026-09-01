<?php

declare(strict_types=1);

namespace App\Domain\Social\Enums;

/**
 * The single error taxonomy the publishing engine understands.
 *
 * Every provider adapter maps its own raw errors into these. The engine never
 * sees a Meta error code or a LinkedIn status string -- that mapping is the
 * whole point of the abstraction.
 *
 * See docs/05-SOCIAL-PROVIDERS.md §9.
 */
enum ProviderErrorClass: string
{
    case RateLimit = 'rate_limit';
    case Network = 'network';
    case Timeout = 'timeout';
    case ServerError = 'server_error';
    case AuthExpired = 'auth_expired';
    case Permission = 'permission';
    case Validation = 'validation';
    case Media = 'media';
    case Duplicate = 'duplicate';
    case PlatformRejection = 'platform_rejection';
    case Unknown = 'unknown';

    public function isRetryable(): bool
    {
        return match ($this) {
            self::RateLimit, self::Network, self::Timeout, self::ServerError => true,
            // Unknown gets exactly one cautious retry, handled by the engine.
            self::Unknown => true,
            self::AuthExpired, self::Permission, self::Validation,
            self::Media, self::Duplicate, self::PlatformRejection => false,
        };
    }

    /**
     * Rate limiting is not the tenant's fault, so waiting must not consume the
     * retry budget. A busy account would otherwise burn its attempts doing
     * nothing wrong.
     */
    public function consumesAttempt(): bool
    {
        return $this !== self::RateLimit;
    }

    /** Does this mean the connection itself needs re-authorising? */
    public function requiresReconnect(): bool
    {
        return $this === self::AuthExpired || $this === self::Permission;
    }

    /**
     * Plain-language cause shown to the user. The raw provider message goes to
     * publication_attempts, visible only to holders of posts.retry.
     */
    public function userMessage(): string
    {
        return match ($this) {
            self::RateLimit => 'Rate limit reached. This will retry automatically.',
            self::Network, self::Timeout => 'The network request failed. Retrying.',
            self::ServerError => 'The platform returned an error. Retrying.',
            self::AuthExpired => 'The connection to this account has expired.',
            self::Permission => 'This connection is missing a required permission.',
            self::Validation => 'The post does not meet this platform\'s requirements.',
            self::Media => 'The attached media is not valid for this platform.',
            self::Duplicate => 'This post may already have been published.',
            self::PlatformRejection => 'The platform rejected this post.',
            self::Unknown => 'An unexpected error occurred.',
        };
    }
}
