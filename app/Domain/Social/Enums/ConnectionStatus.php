<?php

declare(strict_types=1);

namespace App\Domain\Social\Enums;

enum ConnectionStatus: string
{
    case Active = 'active';
    case Expired = 'expired';
    case Revoked = 'revoked';
    case NeedsReconnect = 'needs_reconnect';
    case Error = 'error';

    public function canPublish(): bool
    {
        return $this === self::Active;
    }

    /**
     * Whether the user must re-authorise. Distinguished from a transient error
     * so the UI can show a Reconnect button rather than "try again later".
     */
    public function needsUserAction(): bool
    {
        return match ($this) {
            self::Expired, self::Revoked, self::NeedsReconnect => true,
            self::Active, self::Error => false,
        };
    }
}
