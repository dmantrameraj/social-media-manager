<?php

declare(strict_types=1);

namespace App\Domain\Publishing\Exceptions;

use App\Domain\Publishing\Enums\PostStatus;
use RuntimeException;

/**
 * A status change was attempted that the workflow does not permit.
 *
 * Thrown rather than silently ignored: an approve action landing on an
 * already-published post is a bug worth surfacing, not something to absorb.
 */
final class IllegalTransition extends RuntimeException
{
    public function __construct(
        public readonly PostStatus $from,
        public readonly PostStatus $to,
    ) {
        parent::__construct(
            "A post cannot move from {$from->label()} to {$to->label()}."
        );
    }
}
