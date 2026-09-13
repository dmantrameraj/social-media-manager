<?php

declare(strict_types=1);

namespace App\Domain\Publishing\Enums;

/**
 * How often a recurring rule fires.
 *
 * The three an agency actually schedules against. RFC 5545 also defines
 * SECONDLY through YEARLY; the ones missing here are missing because nobody
 * plans a social calendar by the hour, and a frequency nobody uses is a branch
 * in the materialiser nobody tests.
 */
enum RecurrenceFrequency: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    public function label(): string
    {
        return match ($this) {
            self::Daily => 'Every day',
            self::Weekly => 'Every week',
            self::Monthly => 'Every month',
        };
    }

    /** Weekly rules are the only ones that pick days. */
    public function usesWeekdays(): bool
    {
        return $this === self::Weekly;
    }

    /** Monthly rules are the only ones that pick a date. */
    public function usesDayOfMonth(): bool
    {
        return $this === self::Monthly;
    }
}
