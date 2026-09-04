<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Enums;

/**
 * Whether a reply actually reached the platform.
 *
 * A boolean would lose the difference between "not yet" and "never", which is
 * the difference between waiting and apologising. Inbound messages are
 * Delivered by definition -- they arrived.
 */
enum DeliveryStatus: string
{
    case Pending = 'pending';
    case Delivered = 'delivered';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Sending',
            self::Delivered => 'Sent',
            self::Failed => 'Not sent',
        };
    }

    public function isSettled(): bool
    {
        return $this !== self::Pending;
    }
}
