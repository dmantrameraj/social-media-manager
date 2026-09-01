<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

enum PaymentStatus: string
{
    case Created = 'created';
    case Authorized = 'authorized';
    case Captured = 'captured';
    case Failed = 'failed';
    case Refunded = 'refunded';

    /** Only a captured payment settles an invoice. */
    public function isSettled(): bool
    {
        return $this === self::Captured;
    }

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Created',
            self::Authorized => 'Authorized',
            self::Captured => 'Paid',
            self::Failed => 'Failed',
            self::Refunded => 'Refunded',
        };
    }
}
