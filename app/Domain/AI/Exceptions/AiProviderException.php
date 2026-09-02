<?php

declare(strict_types=1);

namespace App\Domain\AI\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A generation failed at the vendor.
 *
 * The retryable flag is what the calling service uses to decide whether the
 * credit reservation should simply be released (try again) or whether the
 * failure is permanent. Messages are written for the end user; vendor detail
 * stays in ai_generations.
 */
final class AiProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable = false,
        public readonly ?string $providerCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
