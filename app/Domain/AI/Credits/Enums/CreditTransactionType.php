<?php

declare(strict_types=1);

namespace App\Domain\AI\Credits\Enums;

/**
 * Ledger entry types. Balance is DERIVED from these; ai_credit_accounts.balance
 * is only a cache, reconciled on schedule. See docs/08-AI-ARCHITECTURE.md §5.
 *
 * The reserve/commit/release triple exists because AI calls are asynchronous
 * and can fail after the request is sent. Charging on completion allows
 * overspend under concurrency; charging up front overcharges on failure.
 */
enum CreditTransactionType: string
{
    /** Credits added: plan allowance, admin grant, purchased pack. */
    case Grant = 'grant';

    /** Monthly period rollover. */
    case Reset = 'reset';

    /** Held against an in-flight generation. Reduces available, not balance. */
    case Reserve = 'reserve';

    /** Reservation returned unspent (generation failed or was swept). */
    case Release = 'release';

    /** Reservation converted to an actual charge. */
    case Consume = 'consume';

    /** Charge reversed after the fact. */
    case Refund = 'refund';

    /** Manual Super Admin correction. Always carries a reason. */
    case Adjustment = 'adjustment';

    /**
     * Sign convention for the `amount` column, which is stored signed so the
     * ledger sums directly to the balance.
     */
    public function increasesBalance(): bool
    {
        return match ($this) {
            self::Grant, self::Reset, self::Release, self::Refund => true,
            self::Reserve, self::Consume => false,
            // Adjustments carry their own sign.
            self::Adjustment => true,
        };
    }

    /** Does this type move the `reserved` counter rather than `balance`? */
    public function affectsReservation(): bool
    {
        return match ($this) {
            self::Reserve, self::Release, self::Consume => true,
            default => false,
        };
    }
}
