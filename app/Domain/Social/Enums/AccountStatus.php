<?php

declare(strict_types=1);

namespace App\Domain\Social\Enums;

enum AccountStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Disconnected = 'disconnected';
    case NeedsReconnect = 'needs_reconnect';

    public function canPublish(): bool
    {
        return $this === self::Active;
    }

    public function countsTowardLimit(): bool
    {
        return $this !== self::Disconnected;
    }
}
