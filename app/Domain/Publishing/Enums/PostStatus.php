<?php

declare(strict_types=1);

namespace App\Domain\Publishing\Enums;

/**
 * Master post workflow. See docs/06-PUBLISHING-ENGINE.md §4.
 *
 * PartiallyPublished exists because a post to five networks is five
 * independent publications: one provider failing must never mark the whole
 * post failed.
 */
enum PostStatus: string
{
    case Idea = 'idea';
    case Draft = 'draft';
    case InternalReview = 'internal_review';
    case ManagerApproved = 'manager_approved';
    case ClientReview = 'client_review';
    case ClientApproved = 'client_approved';
    case Scheduled = 'scheduled';
    case Processing = 'processing';
    case Published = 'published';
    case PartiallyPublished = 'partially_published';
    case Rejected = 'rejected';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Paused = 'paused';

    /** Visible to a client in the portal only from CLIENT_REVIEW onward. */
    public function isVisibleToPortal(): bool
    {
        return match ($this) {
            self::ClientReview, self::ClientApproved, self::Scheduled,
            self::Processing, self::Published, self::PartiallyPublished,
            self::Rejected, self::Failed => true,
            default => false,
        };
    }

    public function isEditable(): bool
    {
        return match ($this) {
            self::Idea, self::Draft, self::Rejected => true,
            default => false,
        };
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Published, self::Cancelled => true,
            default => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Idea => 'Idea',
            self::Draft => 'Draft',
            self::InternalReview => 'Internal review',
            self::ManagerApproved => 'Manager approved',
            self::ClientReview => 'Awaiting client',
            self::ClientApproved => 'Client approved',
            self::Scheduled => 'Scheduled',
            self::Processing => 'Publishing',
            self::Published => 'Published',
            self::PartiallyPublished => 'Partially published',
            self::Rejected => 'Rejected',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
            self::Paused => 'Paused',
        };
    }
}
