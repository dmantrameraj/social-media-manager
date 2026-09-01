<?php

declare(strict_types=1);

namespace App\Domain\Publishing\Exceptions;

use RuntimeException;

/**
 * The transition is legal, but this actor may not make it.
 *
 * Kept distinct from IllegalTransition so the UI can say "you need approval
 * rights" rather than "that is not possible".
 */
final class UnauthorizedTransition extends RuntimeException
{
    public function __construct(public readonly string $permission)
    {
        parent::__construct("This action requires the [{$permission}] permission.");
    }
}
