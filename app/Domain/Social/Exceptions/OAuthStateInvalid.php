<?php

declare(strict_types=1);

namespace App\Domain\Social\Exceptions;

use RuntimeException;

/**
 * An OAuth callback presented state that could not be accepted.
 *
 * Messages say what the user should do ("this link has expired") without
 * revealing which check failed -- an attacker probing the callback should not
 * learn whether a state existed, belonged to someone else, or simply lapsed.
 */
final class OAuthStateInvalid extends RuntimeException {}
