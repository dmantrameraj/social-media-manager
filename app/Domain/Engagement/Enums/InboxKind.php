<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Enums;

/**
 * What a thread is.
 *
 * One inbox, two shapes. They are separated because the rules differ: most
 * platforms allow replying to a comment indefinitely, and restrict direct
 * messages to a window after the person last wrote. A screen that treated them
 * identically would offer a reply box that silently fails.
 */
enum InboxKind: string
{
    case Comment = 'comment';
    case Message = 'message';

    public function label(): string
    {
        return match ($this) {
            self::Comment => 'Comment',
            self::Message => 'Message',
        };
    }
}
