<?php

declare(strict_types=1);

namespace App\Domain\AI\Exceptions;

use RuntimeException;

/**
 * An AI feature was requested that this tenant cannot use -- the plan does not
 * include AI, or the feature is disabled.
 *
 * Distinct from InsufficientCredits: one is "not on your plan", the other is
 * "you have run out this month". They need different messages and different
 * calls to action.
 */
final class AiFeatureUnavailable extends RuntimeException {}
