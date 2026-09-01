<?php

declare(strict_types=1);

namespace App\Domain\Publishing\Enums;

/**
 * Per-destination publication state. Each target succeeds or fails alone.
 */
enum TargetStatus: string
{
    case Pending = 'pending';
    case Scheduled = 'scheduled';
    case Processing = 'processing';
    case Published = 'published';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Skipped = 'skipped';

    /** Connection needs re-authorising: the content is fine, so it waits. */
    case PausedReconnect = 'paused_reconnect';

    /** Tenant suspended: resumes on reactivation rather than failing. */
    case PausedBilling = 'paused_billing';

    /**
     * A worker died mid-publish. The post may or may not exist on the
     * platform, so it must be checked before anything is retried -- blindly
     * retrying here is the classic way to double-post.
     */
    case NeedsVerification = 'needs_verification';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Published, self::Cancelled, self::Skipped => true,
            default => false,
        };
    }

    public function isPaused(): bool
    {
        return $this === self::PausedReconnect || $this === self::PausedBilling;
    }

    /** Counts as done-successfully when deriving the post's display status. */
    public function isSuccess(): bool
    {
        return $this === self::Published;
    }
}
