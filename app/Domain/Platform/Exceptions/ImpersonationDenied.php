<?php

declare(strict_types=1);

namespace App\Domain\Platform\Exceptions;

use RuntimeException;

/**
 * Every refusal to open an impersonation session.
 *
 * Named constructors rather than one message string, so a test can assert
 * *which* guardrail fired rather than matching on prose.
 */
final class ImpersonationDenied extends RuntimeException
{
    public static function notAnAdministrator(): self
    {
        return new self('Only a Super Admin may impersonate another user.');
    }

    public static function twoFactorRequired(): self
    {
        return new self('Two-factor authentication must be confirmed before impersonating.');
    }

    public static function reasonRequired(): self
    {
        return new self('Impersonation requires a stated reason.');
    }

    /**
     * Impersonating a peer turns one compromised admin account into every
     * admin account, and it defeats the audit trail: the entry would name a
     * second administrator rather than the person who actually acted.
     */
    public static function targetIsAdministrator(): self
    {
        return new self('A Super Admin may not impersonate another Super Admin.');
    }

    public static function self(): self
    {
        return new self('You are already signed in as this user.');
    }
}
