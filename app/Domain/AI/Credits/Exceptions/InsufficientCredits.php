<?php

declare(strict_types=1);

namespace App\Domain\AI\Credits\Exceptions;

use RuntimeException;

/**
 * A reservation was refused because the tenant does not have the credits.
 *
 * Fails closed, and before any provider request is made -- an AI call that is
 * sent and then found to be unaffordable has already cost real money.
 */
final class InsufficientCredits extends RuntimeException
{
    public function __construct(
        public readonly int $requested,
        public readonly int $available,
    ) {
        parent::__construct(
            "This action needs {$requested} AI credits but only {$available} are available."
        );
    }
}
