<?php

declare(strict_types=1);

namespace App\Domain\Audit\Enums;

enum LoginEvent: string
{
    case Login = 'login';
    case Logout = 'logout';
    case Failed = 'failed';
    case TwoFactorFailed = 'two_factor_failed';
    case PasswordReset = 'password_reset';
    case Locked = 'locked';

    /**
     * Events worth FLAGGING on the user's own security screen.
     *
     * Not the same as worth showing: an ordinary sign-in is shown, because
     * "that was not me" is the observation only the account holder can make.
     * It is not flagged, because flagging every sign-in would make the flag
     * mean nothing, and the one that matters would be lost in it.
     */
    public function isSecurityRelevant(): bool
    {
        return match ($this) {
            self::Failed, self::TwoFactorFailed, self::Locked, self::PasswordReset => true,
            self::Login, self::Logout => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Login => 'Signed in',
            self::Logout => 'Signed out',
            self::Failed => 'Failed sign-in',
            self::TwoFactorFailed => 'Failed two-factor code',
            self::PasswordReset => 'Password reset',
            self::Locked => 'Account locked',
        };
    }
}
