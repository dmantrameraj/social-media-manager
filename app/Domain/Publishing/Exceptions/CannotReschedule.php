<?php

declare(strict_types=1);

namespace App\Domain\Publishing\Exceptions;

use RuntimeException;

/**
 * A post's schedule was moved when it was not safe to move it.
 *
 * Separate from IllegalTransition because rescheduling is not a status change:
 * a Scheduled post that moves to next Tuesday is still Scheduled. The
 * constraint is about work already in flight, not about the workflow.
 */
final class CannotReschedule extends RuntimeException
{
    public static function status(string $label): self
    {
        return new self("A {$label} post cannot be rescheduled.");
    }

    public static function inFlight(): self
    {
        return new self(
            'This post is being published right now. Wait for it to finish before moving it.'
        );
    }

    public static function tooSoon(int $seconds): self
    {
        return new self(
            "Choose a time at least {$seconds} seconds from now."
        );
    }
}
