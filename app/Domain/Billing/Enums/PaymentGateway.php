<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/**
 * Manual is a real gateway, not a special case.
 *
 * ManualGateway implements PaymentGatewayInterface with no-op payment
 * operations, so admin-activated tenants traverse exactly the same lifecycle
 * code as paying ones. There is no "if manual" branch anywhere in
 * Domain/Billing/Subscriptions. See docs/09-BILLING.md §1.
 */
enum PaymentGateway: string
{
    case Razorpay = 'razorpay';
    case Manual = 'manual';

    /** Does this gateway send webhooks we must reconcile against? */
    public function isExternal(): bool
    {
        return $this !== self::Manual;
    }

    public function label(): string
    {
        return match ($this) {
            self::Razorpay => 'Razorpay',
            self::Manual => 'Manual / admin activated',
        };
    }
}
