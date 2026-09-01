<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Exceptions;

use RuntimeException;

/**
 * An invitation could not be consumed.
 *
 * The message is written to be shown to the user: "this invitation expired" is
 * actionable, "invalid link" is not. It deliberately never reveals whether an
 * unknown token corresponds to a real workspace.
 */
final class InvitationUnusable extends RuntimeException {}
