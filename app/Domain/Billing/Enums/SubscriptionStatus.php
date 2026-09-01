<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

enum SubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Grace = 'grace';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    /**
     * Does this subscription currently grant its plan's entitlements?
     *
     * past_due and grace still do: the customer is mid-recovery and cutting
     * entitlements off is what turns a failed card into a churned account.
     */
    public function grantsEntitlements(): bool
    {
        return match ($this) {
            self::Trialing, self::Active, self::PastDue, self::Grace => true,
            self::Cancelled, self::Expired => false,
        };
    }

    /**
     * Non-terminal states occupy the "one active subscription per tenant" slot.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Cancelled, self::Expired => true,
            default => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Trialing => 'Trialing',
            self::Active => 'Active',
            self::PastDue => 'Past due',
            self::Grace => 'Grace period',
            self::Cancelled => 'Cancelled',
            self::Expired => 'Expired',
        };
    }
}
