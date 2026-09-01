<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

enum CouponDuration: string
{
    /** Applies to the first invoice only. */
    case Once = 'once';

    /** Applies for duration_months billing periods. */
    case Repeating = 'repeating';

    /** Applies to every invoice for the life of the subscription. */
    case Forever = 'forever';

    public function requiresMonths(): bool
    {
        return $this === self::Repeating;
    }
}
