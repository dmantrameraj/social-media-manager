<?php

declare(strict_types=1);

namespace App\Domain\AI\Exceptions;

use InvalidArgumentException;

/**
 * An AI feature key was requested that nothing implements.
 *
 * Loud rather than silent: keys arrive from requests, and a quietly ignored
 * one would look like a feature that simply does nothing.
 */
final class UnknownAiFeature extends InvalidArgumentException
{
    public function __construct(string $key)
    {
        parent::__construct("No AI feature is registered for key [{$key}].");
    }
}
