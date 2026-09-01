<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

enum UserStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';

    public function canAuthenticate(): bool
    {
        return $this === self::Active;
    }

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Disabled => 'Disabled',
        };
    }
}
