<?php

declare(strict_types=1);

namespace App\Domain\Customers\Services;

use App\Domain\Identity\Models\CustomerPortalUser;
use App\Domain\Publishing\Enums\PostStatus;
use App\Domain\Publishing\Models\Post;
use Illuminate\Database\Eloquent\Builder;

/**
 * The single definition of what a client may see.
 *
 * Every portal read goes through here rather than each controller writing its
 * own where-clauses. Two filters, and both are load-bearing:
 *
 *   1. brand assignment -- a portal user may hold access to some of an
 *      agency's clients and not others
 *   2. workflow stage -- nothing below CLIENT_REVIEW has been shown to the
 *      client on purpose. Drafts contain half-written copy, internal notes and
 *      ideas the agency has not decided to propose.
 *
 * Centralising it means a new portal screen cannot accidentally widen the
 * boundary, and a test can assert the boundary in one place.
 */
final class PortalPostQuery
{
    /**
     * Statuses a client is allowed to see.
     *
     * An allow-list, not "anything past client_review": the ordering of an
     * enum is not a security boundary, and a status inserted in the middle
     * later must not silently become visible.
     *
     * @var list<PostStatus>
     */
    public const VISIBLE_STATUSES = [
        PostStatus::ClientReview,
        PostStatus::ClientApproved,
        PostStatus::Scheduled,
        PostStatus::Processing,
        PostStatus::Published,
        PostStatus::PartiallyPublished,
        PostStatus::Rejected,
    ];

    /** @return Builder<Post> */
    public function for(CustomerPortalUser $user): Builder
    {
        $brandIds = $user->assignedCustomerIds();

        return Post::query()
            /*
             | An empty assignment list must match nothing. whereIn with an
             | empty array does that correctly, but it is stated here because
             | the failure mode of getting it wrong -- every post in the tenant
             | -- is the worst possible one.
             */
            ->whereIn('customer_id', $brandIds->all())
            ->whereIn('status', array_map(
                static fn (PostStatus $status): string => $status->value,
                self::VISIBLE_STATUSES,
            ));
    }

    /**
     * Posts the client still has to answer.
     *
     * @return Builder<Post>
     */
    public function awaitingReview(CustomerPortalUser $user): Builder
    {
        return $this->for($user)->where('status', PostStatus::ClientReview->value);
    }

    public function isVisible(CustomerPortalUser $user, Post $post): bool
    {
        return $user->canAccessCustomer($post->customer_id)
            && in_array($post->status, self::VISIBLE_STATUSES, true);
    }
}
