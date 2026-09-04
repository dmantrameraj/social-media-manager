<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Enums;

/**
 * Where a conversation has got to.
 *
 * Three states, not two. "Pending" is the one that earns its place: a reply
 * has been sent and the agency is waiting on the other person, which is
 * neither finished nor still needing attention. Without it, everything
 * answered but unresolved either clutters the open queue or disappears.
 */
enum InboxStatus: string
{
    case Open = 'open';
    case Pending = 'pending';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Pending => 'Waiting on them',
            self::Closed => 'Closed',
        };
    }

    /** Does this thread still want somebody's attention? */
    public function needsAttention(): bool
    {
        return $this === self::Open;
    }
}
