<?php

declare(strict_types=1);

namespace App\Domain\Customers\Enums;

enum CustomerStatus: string
{
    case Active = 'active';
    case Archived = 'archived';

    /**
     * Archived brands stop counting against the brands.max entitlement and
     * their scheduled posts are paused, but nothing is deleted.
     */
    public function countsTowardLimit(): bool
    {
        return $this === self::Active;
    }

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Archived => 'Archived',
        };
    }
}
