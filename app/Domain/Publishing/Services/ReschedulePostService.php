<?php

declare(strict_types=1);

namespace App\Domain\Publishing\Services;

use App\Domain\Audit\AuditLogger;
use App\Domain\Publishing\Enums\PostStatus;
use App\Domain\Publishing\Enums\TargetStatus;
use App\Domain\Publishing\Exceptions\CannotReschedule;
use App\Domain\Publishing\Models\Post;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Moves a post's scheduled time, and its targets' with it.
 *
 * The second half of that sentence is the whole reason this is a service and
 * not two lines in a controller. The dispatcher reads `post_targets.
 * scheduled_at`, never the post's -- so a reschedule that updates only the post
 * changes what the calendar SHOWS and nothing about when the post GOES OUT.
 * That failure is silent, and it is discovered by a client.
 */
final class ReschedulePostService
{
    /**
     * Statuses whose schedule is not ours to move.
     *
     * Published and Cancelled are over. Processing means a worker holds at
     * least one target right now. PartiallyPublished has already put content
     * on a network under the old time, so moving the remainder is a decision
     * about the failed targets specifically -- that is what retry is for.
     *
     * @var list<PostStatus>
     */
    private const FROZEN = [
        PostStatus::Processing,
        PostStatus::Published,
        PostStatus::PartiallyPublished,
        PostStatus::Cancelled,
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  Carbon  $at  an instant, already resolved out of the post's timezone
     *
     * @throws CannotReschedule
     */
    public function execute(Post $post, Carbon $at, ?Authenticatable $actor = null): Post
    {
        if (in_array($post->status, self::FROZEN, true)) {
            throw CannotReschedule::status(mb_strtolower($post->status->label()));
        }

        /*
         | The status check above is not enough on its own. A post is only
         | moved to Processing once the FIRST target is claimed, so there is a
         | window where the post still reads Scheduled while a worker is
         | already holding a target. Checking the targets closes it.
         */
        $inFlight = $post->targets()
            ->where('status', TargetStatus::Processing->value)
            ->exists();

        if ($inFlight) {
            throw CannotReschedule::inFlight();
        }

        $lead = (int) config('publishing.min_lead_seconds', 60);

        /*
         | The same floor the composer enforces, for the same reason: a time
         | inside the sweeper's current pass is a post that may go out before
         | anyone can look at it again.
         */
        if ($at->lte(now()->addSeconds($lead))) {
            throw CannotReschedule::tooSoon($lead);
        }

        $was = $post->scheduled_at?->copy();

        DB::transaction(function () use ($post, $at): void {
            $post->forceFill(['scheduled_at' => $at])->save();

            /*
             | Only targets that have not gone out. A published target keeps
             | the time it actually published at -- that is history, and
             | rewriting it would make the publication log disagree with the
             | network. Cancelled and skipped targets are equally not ours.
             */
            $post->targets()
                ->whereNotIn('status', [
                    TargetStatus::Published->value,
                    TargetStatus::Cancelled->value,
                    TargetStatus::Skipped->value,
                ])
                ->update([
                    'scheduled_at' => $at,
                    /*
                      | A backoff computed against the old time would hold a
                      | freshly moved target back for a delay that belongs to a
                      | schedule that no longer exists.
                      */
                    'next_attempt_at' => null,
                ]);
        });

        $this->audit->log(
            action: 'post.rescheduled',
            auditable: $post,
            oldValues: ['scheduled_at' => $was?->toIso8601String()],
            newValues: ['scheduled_at' => $at->toIso8601String()],
            actor: $actor,
        );

        return $post->refresh();
    }

    /**
     * Interpret a wall-clock string in the post's own timezone.
     *
     * The POST's timezone, not the brand's current one. A brand that moves
     * from Europe/London to Asia/Kolkata must not silently drag every already
     * scheduled post six hours; the post snapshotted the zone it was written
     * in, and that is the promise made to whoever wrote it.
     */
    public function resolve(Post $post, string $wallClock): Carbon
    {
        return Carbon::parse($wallClock, $post->timezone ?: config('app.timezone'))->utc();
    }

    /**
     * Whether a status permits moving, for rendering a list without a query
     * per row.
     *
     * The caller still has to rule out targets in flight -- see the calendar,
     * which does it for a whole month in one query. The server decides again
     * on submit either way; this only keeps the UI from offering what will be
     * refused.
     */
    public static function statusPermitsMove(PostStatus $status): bool
    {
        return ! in_array($status, self::FROZEN, true);
    }

    /** Whether this one post could be moved. */
    public function isMovable(Post $post): bool
    {
        if (! self::statusPermitsMove($post->status)) {
            return false;
        }

        return ! $post->targets()
            ->where('status', TargetStatus::Processing->value)
            ->exists();
    }
}
