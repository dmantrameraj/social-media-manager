<?php

declare(strict_types=1);

namespace App\Domain\Social\Exceptions;

use InvalidArgumentException;

/**
 * A provider key was requested that nothing registered.
 *
 * Loud rather than silent: resolving to a null adapter would surface much
 * later as a mysterious publish failure.
 */
final class UnknownProvider extends InvalidArgumentException
{
    public function __construct(string $key)
    {
        parent::__construct("No social provider is registered for key [{$key}].");
    }
}
