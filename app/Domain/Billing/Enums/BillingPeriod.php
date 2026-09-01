<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

use Carbon\CarbonInterface;

enum BillingPeriod: string
{
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    public function advance(CarbonInterface $from): CarbonInterface
    {
        return match ($this) {
            // addMonthNoOverflow: billing on the 31st must not skip February.
            self::Monthly => $from->copy()->addMonthNoOverflow(),
            self::Yearly => $from->copy()->addYearNoOverflow(),
        };
    }

    public function months(): int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Yearly => 12,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Monthly',
            self::Yearly => 'Yearly',
        };
    }
}
