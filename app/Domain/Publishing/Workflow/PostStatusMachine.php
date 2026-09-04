<?php

declare(strict_types=1);

namespace App\Domain\Publishing\Workflow;

use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\Enums\ActorType;
use App\Domain\Billing\Entitlements\EntitlementResolver;
use App\Domain\Identity\Models\CustomerPortalUser;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\PostEventDispatcher;
use App\Domain\Publishing\Enums\PostStatus;
use App\Domain\Publishing\Exceptions\IllegalTransition;
use App\Domain\Publishing\Exceptions\UnauthorizedTransition;
use App\Domain\Publishing\Models\Post;
use App\Domain\Publishing\Models\PostApproval;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The only thing permitted to change a post's status.
 *
 * Status is never assigned by direct attribute write anywhere in the codebase;
 * an architecture test asserts that. Centralising it is what makes "who moved
 * this post, when, and were they allowed to" answerable for every transition.
 *
 * See docs/06-PUBLISHING-ENGINE.md §4.
 */
final class PostStatusMachine
{
    /**
     * Legal transitions. Anything not listed is impossible by construction
     * rather than by convention.
     *
     * @var array<string, list<PostStatus>>
     */
    private const TRANSITIONS = [
        'idea' => [PostStatus::Draft, PostStatus::Cancelled],

        'draft' => [PostStatus::InternalReview, PostStatus::Scheduled, PostStatus::Cancelled],

        'internal_review' => [
            PostStatus::ManagerApproved, PostStatus::Rejected, PostStatus::Draft,
        ],

        // Either straight to scheduling, or out to the client first.
        'manager_approved' => [
            PostStatus::ClientReview, PostStatus::Scheduled,
            PostStatus::Draft, PostStatus::Cancelled,
        ],

        'client_review' => [
            PostStatus::ClientApproved, PostStatus::Rejected, PostStatus::Draft,
        ],

        'client_approved' => [PostStatus::Scheduled, PostStatus::Draft, PostStatus::Cancelled],

        'scheduled' => [PostStatus::Processing, PostStatus::Draft, PostStatus::Cancelled, PostStatus::Paused],

        'processing' => [
            PostStatus::Published, PostStatus::PartiallyPublished,
            PostStatus::Failed, PostStatus::Paused,
        ],

        // A failed post can be retried, which puts it back in the queue.
        'failed' => [PostStatus::Scheduled, PostStatus::Draft, PostStatus::Cancelled],

        'partially_published' => [PostStatus::Scheduled, PostStatus::Published, PostStatus::Failed],

        // Rejection returns to draft on edit, which is the normal recovery.
        'rejected' => [PostStatus::Draft, PostStatus::Cancelled],

        'paused' => [PostStatus::Scheduled, PostStatus::Draft, PostStatus::Cancelled],

        'published' => [],
        'cancelled' => [],
    ];

    /** Permission required to make each transition. */
    private const REQUIRED_PERMISSIONS = [
        'internal_review' => 'posts.update',
        'manager_approved' => 'posts.approve_internal',
        'client_review' => 'posts.update',
        'scheduled' => 'posts.schedule',
        'cancelled' => 'posts.update',
        'draft' => 'posts.update',
        'rejected' => 'posts.approve_internal',
    ];

    /**
     * The only destinations a client may move a post to.
     *
     * An allow-list rather than a deny-list: a status added to TRANSITIONS
     * later must not silently become something a client can do.
     *
     * Draft is here because "request changes" returns the post to the agency,
     * which is a client's legitimate third answer alongside approve and reject.
     *
     * @var list<PostStatus>
     */
    private const PORTAL_TRANSITIONS = [
        PostStatus::ClientApproved,
        PostStatus::Rejected,
        PostStatus::Draft,
    ];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PostEventDispatcher $notifications,
        private readonly EntitlementResolver $entitlements,
    ) {}

    public function canTransition(Post $post, PostStatus $to): bool
    {
        return in_array($to, $this->allowedFrom($post->status), true);
    }

    /**
     * Destinations reachable from a status.
     *
     * A status absent from the map has no legal moves, which is the correct
     * reading for a terminal state.
     *
     * @return list<PostStatus>
     */
    public function allowedFrom(PostStatus $from): array
    {
        $map = self::TRANSITIONS;

        return array_key_exists($from->value, $map) ? $map[$from->value] : [];
    }

    /**
     * @throws IllegalTransition
     * @throws UnauthorizedTransition
     */
    public function assertCan(Post $post, PostStatus $to, ?Authenticatable $actor = null): void
    {
        if (! $this->canTransition($post, $to)) {
            throw new IllegalTransition($post->status, $to);
        }

        /*
         | Portal actors are checked FIRST, before the agency permission lookup.
         |
         | That ordering is the whole fix: REQUIRED_PERMISSIONS is a map of
         | agency permissions and has no entry for client_approved, so the
         | `$permission === null` early return below fired before any portal
         | check could run. A view-only client could approve a post, and the
         | check that was supposed to stop them sat a few lines too far down.
         |
         | Rights are per brand (customer_portal_user_customer.role), so this
         | tests against THIS post's brand rather than a global permission.
         */
        if ($actor instanceof CustomerPortalUser) {
            if (! $actor->canAccessCustomer($post->customer_id)) {
                throw new UnauthorizedTransition('portal.posts.view');
            }

            if (! in_array($to, self::PORTAL_TRANSITIONS, true)) {
                // Scheduling, cancelling and publishing are agency decisions.
                throw new UnauthorizedTransition('portal.posts.'.$to->value);
            }

            if (! $actor->canApproveFor($post->customer_id)) {
                throw new UnauthorizedTransition('portal.posts.approve');
            }

            return;
        }

        /*
         | The plan limit, checked here rather than in a controller.
         |
         | Scheduling is the metered act -- it is what a plan sells by the
         | month -- and this is the one place every path to Scheduled passes
         | through: the composer, the calendar, a retry, a future API. A check
         | in PostController would be a check the console and the queue skip,
         | which is the same reason guard() says it belongs in a service.
         |
         | Usage counts DISTINCT posts, so re-scheduling one that has already
         | been counted -- a retry after a failure, a post moved and moved
         | again -- costs nothing and cannot strand a tenant at their limit
         | with a post they cannot recover.
         */
        if ($to === PostStatus::Scheduled) {
            $tenant = Tenant::query()->find($post->tenant_id);

            /*
             | Only a post that has not already been counted this period. A
             | retry after a failure, or a post moved back to draft and out
             | again, is the same post -- and a tenant at their limit who could
             | not retry would have paid for a post that never went out.
             */
            if ($tenant !== null && ! $this->entitlements->alreadyScheduledThisPeriod($tenant, $post->getKey())) {
                $this->entitlements->guard($tenant, 'posts.scheduled_per_month');
            }
        }

        $permissions = self::REQUIRED_PERMISSIONS;
        $permission = array_key_exists($to->value, $permissions) ? $permissions[$to->value] : null;

        // System transitions (the scheduler moving scheduled -> processing)
        // have no actor and are not permission-checked; they are only ever
        // reachable from engine code.
        if ($permission === null || $actor === null) {
            return;
        }

        if ($actor instanceof User && ! $actor->can($permission)) {
            throw new UnauthorizedTransition($permission);
        }
    }

    /**
     * Move a post, recording who did it and why.
     *
     * The approval row and the audit entry are written in the same transaction
     * as the status change, so history can never disagree with state.
     */
    public function transition(
        Post $post,
        PostStatus $to,
        ?Authenticatable $actor = null,
        ?string $comment = null,
        string $stage = 'internal',
    ): Post {
        $this->assertCan($post, $to, $actor);

        $post = DB::transaction(function () use ($post, $to, $actor, $comment, $stage): Post {
            $from = $post->status;

            $post->forceFill(['status' => $to->value]);

            if ($to === PostStatus::InternalReview) {
                $post->forceFill(['submitted_at' => now()]);
            }

            if ($to === PostStatus::ClientApproved || $to === PostStatus::ManagerApproved) {
                $post->forceFill(['approved_at' => now()]);
            }

            $post->save();

            PostApproval::query()->forceCreate([
                'tenant_id' => $post->tenant_id,
                'post_id' => $post->getKey(),
                'stage' => $stage,
                'action' => $this->actionFor($to),
                'actor_type' => $this->actorType($actor)->value,
                'actor_id' => $actor?->getAuthIdentifier(),
                'comment' => $comment,
                'from_status' => $from->value,
                'to_status' => $to->value,
                'created_at' => now(),
            ]);

            $this->audit->log(
                action: 'post.status_changed',
                auditable: $post,
                oldValues: ['status' => $from->value],
                newValues: ['status' => $to->value],
                actor: $actor,
            );

            return $post;
        });

        /*
         | Notifications are dispatched AFTER the transaction returns, never
         | inside it.
         |
         | A queued job enqueued inside an open transaction can be picked up by
         | a worker before the commit lands, and then queries a post that does
         | not exist yet. That race only appears under load and reads in
         | production as a phantom "post not found".
         |
         | Delivery is also not allowed to fail the transition. A post that
         | moved but whose email bounced is a missing email; a transition rolled
         | back because a mail server was down is a lost decision.
         */
        $event = $this->notifications->eventFor($to, $actor);

        if ($event !== null) {
            try {
                $this->notifications->dispatch($event, $post, $comment);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $post;
    }

    private function actionFor(PostStatus $to): string
    {
        return match ($to) {
            PostStatus::InternalReview, PostStatus::ClientReview => 'submitted',
            PostStatus::ManagerApproved, PostStatus::ClientApproved => 'approved',
            PostStatus::Rejected => 'rejected',
            PostStatus::Draft => 'changes_requested',
            default => 'transitioned',
        };
    }

    private function actorType(?Authenticatable $actor): ActorType
    {
        return match (true) {
            $actor instanceof User => ActorType::User,
            $actor instanceof CustomerPortalUser => ActorType::CustomerPortalUser,
            default => ActorType::System,
        };
    }
}
