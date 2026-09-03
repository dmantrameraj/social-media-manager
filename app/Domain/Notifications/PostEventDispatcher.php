<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Domain\Customers\Enums\PortalRole;
use App\Domain\Identity\Models\CustomerPortalUser;
use App\Domain\Identity\Models\User;
use App\Domain\Publishing\Enums\PostStatus;
use App\Domain\Publishing\Models\Post;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Turns a post transition into messages to the right people.
 *
 * The audience decision is the whole job, and it is where a mistake is
 * expensive: sending an agency-audience event to a portal user would put
 * internal language -- "the client rejected this" -- in front of the client it
 * is about. The catalogue declares the audience, this class honours it, and a
 * test asserts no client ever receives an agency event.
 */
final class PostEventDispatcher
{
    public function __construct(private readonly NotificationPreferences $preferences) {}

    /**
     * The transition that produced this event, mapped to an event key.
     *
     * Returns null for transitions nobody needs to hear about. Most status
     * changes are internal bookkeeping, and a product that emails on all of
     * them teaches people to filter it away -- including the failures.
     */
    public function eventFor(PostStatus $to, ?Authenticatable $actor): ?string
    {
        $fromClient = $actor instanceof CustomerPortalUser;

        return match (true) {
            $to === PostStatus::ClientReview => 'post.client_review',

            // Approve, reject and send-back only count as client decisions when
            // a client actually made them. The same transitions happen inside
            // the agency, and the agency does not need telling what it just did.
            $to === PostStatus::ClientApproved && $fromClient => 'post.client_approved',
            $to === PostStatus::Rejected && $fromClient => 'post.client_rejected',
            $to === PostStatus::Draft && $fromClient => 'post.changes_requested',

            $to === PostStatus::Published => 'post.published',
            $to === PostStatus::Failed => 'post.publish_failed',

            default => null,
        };
    }

    public function dispatch(string $event, Post $post, ?string $comment = null): void
    {
        $recipients = $this->preferences->isClientEvent($event)
            ? $this->clientsFor($post)
            : $this->agencyTeamFor($post);

        if ($recipients->isEmpty()) {
            return;
        }

        /*
         | Notification::send rather than $recipient->notify in a loop: the
         | channel resolution still runs per recipient (via() receives each
         | notifiable), but one call keeps the queueing behaviour uniform.
         */
        Notification::send($recipients, PostEventNotification::for($event, $post, $comment));
    }

    /**
     * Portal users assigned to this post's brand.
     *
     * Viewers are included as well as approvers: someone with read-only access
     * is still expected to look, and excluding them would mean a brand whose
     * only portal user is a viewer never hears that anything arrived.
     *
     * @return Collection<int, CustomerPortalUser>
     */
    private function clientsFor(Post $post): Collection
    {
        return CustomerPortalUser::query()
            ->whereHas('customers', fn ($query) => $query->whereKey($post->customer_id))
            ->get()
            ->filter(fn (CustomerPortalUser $user): bool => $user->canAuthenticate())
            ->values();
    }

    /**
     * The agency people who should hear about this post.
     *
     * The author first, because it is their work. Then anyone else assigned to
     * the brand who can act on it -- a rejection that only reaches someone on
     * holiday is a rejection nobody answers.
     *
     * @return Collection<int, User>
     */
    private function agencyTeamFor(Post $post): Collection
    {
        $recipients = User::query()
            ->whereHas('tenants', fn ($query) => $query->whereKey($post->tenant_id))
            ->get()
            ->filter(fn (User $user): bool => $user->canAuthenticate())
            // Assignment is a real boundary, not a preference: a user
            // restricted to some brands must not be told about another's work.
            ->filter(fn (User $user): bool => $user->canAccessCustomer($post->customer_id));

        if ($post->created_by_user_id !== null
            && ! $recipients->contains(fn (User $user): bool => $user->getKey() === $post->created_by_user_id)) {
            $author = User::query()->find($post->created_by_user_id);

            if ($author instanceof User && $author->canAuthenticate()) {
                $recipients->push($author);
            }
        }

        return $recipients->unique(fn (User $user): int => $user->getKey())->values();
    }

    /** Whether a portal role should hear about content arriving. Kept for clarity. */
    public function notifies(PortalRole $role): bool
    {
        // Both roles. A viewer is still expected to look at what arrived.
        return in_array($role, [PortalRole::Approver, PortalRole::Viewer], true);
    }
}
