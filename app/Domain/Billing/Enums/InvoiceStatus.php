<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case Paid = 'paid';
    case Void = 'void';
    case Uncollectible = 'uncollectible';

    /**
     * A draft invoice has not been issued and holds no number yet. Numbers are
     * allocated at issue time under a row lock so the sequence stays gapless.
     */
    public function isIssued(): bool
    {
        return $this !== self::Draft;
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Open => 'Open',
            self::Paid => 'Paid',
            self::Void => 'Void',
            self::Uncollectible => 'Uncollectible',
        };
    }
}
