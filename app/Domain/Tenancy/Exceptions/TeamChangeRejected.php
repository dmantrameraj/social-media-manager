<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Exceptions;

use RuntimeException;

/**
 * A team change that is refused for a reason the user should be told.
 *
 * Distinct from an authorisation failure: the actor is allowed to manage the
 * team, and the change is still wrong -- suspending yourself, or removing the
 * last person who could undo it. The message is written to be shown.
 */
final class TeamChangeRejected extends RuntimeException {}
