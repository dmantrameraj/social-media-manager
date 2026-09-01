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
     * Events worth surfacing on the user's own security screen. Successful
     * logins and lockouts are what let a user notice a compromise.
     */
    public function isSecurityRelevant(): bool
    {
        return match ($this) {
            self::Failed, self::TwoFactorFailed, self::Locked, self::PasswordReset => true,
            self::Login, self::Logout => false,
        };
    }
}
