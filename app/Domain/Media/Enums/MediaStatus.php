<?php

declare(strict_types=1);

namespace App\Domain\Media\Enums;

enum MediaStatus: string
{
    /** Row created, bytes not yet fully written. */
    case Uploading = 'uploading';

    /** Bytes stored; variants/thumbnails still being generated on the queue. */
    case Processing = 'processing';

    case Ready = 'ready';
    case Failed = 'failed';

    /**
     * Only ready media may be attached to a post. Publishing validation
     * re-checks this immediately before sending -- media can be deleted or
     * fail processing between scheduling and publishing.
     */
    public function isUsable(): bool
    {
        return $this === self::Ready;
    }

    /**
     * Media still being written should not count against the storage
     * entitlement until its final size is known.
     */
    public function countsTowardStorage(): bool
    {
        return $this === self::Ready || $this === self::Processing;
    }
}
