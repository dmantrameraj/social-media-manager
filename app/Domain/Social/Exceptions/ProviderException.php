<?php

declare(strict_types=1);

namespace App\Domain\Social\Exceptions;

use App\Domain\Social\Enums\ProviderErrorClass;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * The only exception type the publishing engine handles from a provider.
 *
 * Adapters map their raw errors into a ProviderErrorClass before throwing, so
 * the engine's retry logic never has to know a Meta subcode from a LinkedIn
 * status. See docs/05-SOCIAL-PROVIDERS.md §9.
 */
final class ProviderException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context  redacted before it is persisted
     */
    public function __construct(
        public readonly ProviderErrorClass $errorClass,
        string $message,
        public readonly ?string $providerCode = null,
        public readonly ?int $httpStatus = null,
        public readonly ?Carbon $retryAfter = null,
        public readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function isRetryable(): bool
    {
        return $this->errorClass->isRetryable();
    }

    public function consumesAttempt(): bool
    {
        return $this->errorClass->consumesAttempt();
    }

    public function requiresReconnect(): bool
    {
        return $this->errorClass->requiresReconnect();
    }

    /** Safe to show a user; the raw message is not. */
    public function userMessage(): string
    {
        return $this->errorClass->userMessage();
    }

    // ------------------------------------------------------------ convenience

    public static function rateLimited(string $message, ?Carbon $retryAfter = null): self
    {
        return new self(ProviderErrorClass::RateLimit, $message, retryAfter: $retryAfter);
    }

    public static function authExpired(string $message): self
    {
        return new self(ProviderErrorClass::AuthExpired, $message);
    }

    public static function validation(string $message): self
    {
        return new self(ProviderErrorClass::Validation, $message);
    }

    /**
     * The provider says this is a duplicate.
     *
     * If an external id can be recovered the engine treats it as SUCCESS; if
     * not, the target fails with duplicate_unresolved and is NOT auto-retried,
     * because a human must check the platform first.
     */
    public static function duplicate(string $message, ?string $externalId = null): self
    {
        return new self(
            ProviderErrorClass::Duplicate,
            $message,
            context: $externalId !== null ? ['external_id' => $externalId] : [],
        );
    }

    public function recoveredExternalId(): ?string
    {
        $id = $this->context['external_id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }
}
