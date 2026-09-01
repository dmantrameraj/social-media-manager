<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

enum CouponType: string
{
    case Percent = 'percent';
    case Fixed = 'fixed';

    /**
     * Discount in minor units. Percent coupons store value as basis points of
     * a percent (value = 20 means 20%); fixed coupons store minor units
     * directly.
     *
     * Clamped at the subtotal so a discount can never produce a negative total.
     */
    public function discountFor(int $subtotalMinor, int $value): int
    {
        $discount = match ($this) {
            self::Percent => (int) round($subtotalMinor * $value / 100),
            self::Fixed => $value,
        };

        return max(0, min($discount, $subtotalMinor));
    }
}
