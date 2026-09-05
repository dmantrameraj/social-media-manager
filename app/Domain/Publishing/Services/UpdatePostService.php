<?php

declare(strict_types=1);

namespace App\Domain\Publishing\Services;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Media\Models\Media;
use App\Domain\Publishing\Enums\PostStatus;
use App\Domain\Publishing\Enums\TargetStatus;
use App\Domain\Publishing\Exceptions\PostNotEditable;
use App\Domain\Publishing\Models\Post;
use App\Domain\Publishing\Models\PostTarget;
use App\Domain\Publishing\Models\PostVersion;
use App\Domain\Publishing\Workflow\PostStatusMachine;
use App\Domain\Social\Models\SocialAccount;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Changing a post after it was written.
 *
 * There was no way to do this at all. A post could be created and moved
 * through the workflow, and its words could never be altered -- which made
 * "rejected" a dead end rather than the round trip the status machine
 * describes ("rejection returns to draft on edit, which is the normal
 * recovery"). PostStatus::isEditable() had been sitting in the enum since
 * Phase 3 with no caller.
 */
final class UpdatePostService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PostStatusMachine $machine,
    ) {}

    /**
     * @param  Collection<int, SocialAccount>  $accounts
     * @param  Collection<int, Media>  $media
     *
     * @throws PostNotEditable
     */
    public function execute(
        Post $post,
        ?string $title,
        string $body,
        ?string $scheduledAt,
        Collection $accounts,
        Collection $media,
        ?Authenticatable $actor = null,
    ): Post {
        /*
         | isEditable() is the enum's own answer -- Idea, Draft and Rejected --
         | and this is its first caller. Everything past those states has been
         | approved by somebody or has already gone out, and silently changing
         | the words under an approval would make the approval a lie.
         */
        if (! $post->status->isEditable()) {
            throw PostNotEditable::status($post->status);
        }

        $before = [
            'title' => $post->title,
            'body' => $post->body,
            'scheduled_at' => $post->scheduled_at?->toIso8601String(),
        ];

        $wasRejected = $post->status === PostStatus::Rejected;

        DB::transaction(function () use ($post, $title, $body, $scheduledAt, $accounts, $media, $actor): void {
            /*
             | Keep what it used to say, BEFORE overwriting it.
             |
             | An edit replaces words a manager or a client agreed to. Without
             | this there is no way to answer "what did they actually approve?"
             | three weeks later, when the post on the feed and the post in the
             | database no longer match.
             |
             | Superseded states only: the current text stays on the post row,
             | so the two can never disagree about which is authoritative.
             */
            $this->keepPreviousVersion($post, $actor);

            $post->title = $title;
            $post->body = $body;
            $post->content_type = Post::deriveContentType($media);

            // Interpreted in the POST's zone, not the brand's current one --
            // the same promise the reschedule path makes.
            $post->scheduled_at = $scheduledAt === null || $scheduledAt === ''
                ? null
                : Carbon::parse($scheduledAt, $post->timezone ?: config('app.timezone'))->utc();

            $post->save();

            $this->syncTargets($post, $accounts);
            $this->syncMedia($post, $media);
        });

        $this->audit->log(
            action: 'post.updated',
            auditable: $post,
            oldValues: $before,
            newValues: [
                'title' => $post->title,
                'body' => $post->body,
                'scheduled_at' => $post->scheduled_at?->toIso8601String(),
            ],
            actor: $actor,
        );

        /*
         | Editing a rejected post returns it to draft, which is what the
         | status machine's own map already anticipated. Leaving it Rejected
         | after the author addressed the rejection would strand it: the only
         | other move from Rejected is Cancelled.
         |
         | Through the machine, so the approval trail records the recovery
         | rather than a status appearing to change by itself.
         */
        if ($wasRejected) {
            $this->machine->transition($post, PostStatus::Draft, $actor, 'Edited after rejection.');
        }

        return $post->refresh();
    }

    /**
     * Append the state this edit is about to replace.
     *
     * Version numbers come from a count rather than a sequence: the table is
     * unique on (post_id, version), so two concurrent edits would collide
     * rather than silently interleave, and a collision here is the correct
     * outcome -- the second edit should be retried against fresh text, not
     * quietly filed under a number it invented.
     */
    private function keepPreviousVersion(Post $post, ?Authenticatable $actor): void
    {
        PostVersion::query()->forceCreate([
            'tenant_id' => $post->tenant_id,
            'post_id' => $post->getKey(),
            'version' => $post->versions()->count() + 1,
            'body' => $post->body,
            'meta' => [
                'title' => $post->title,
                'status' => $post->status->value,
                'scheduled_at' => $post->scheduled_at?->toIso8601String(),
            ],
            'created_by_user_id' => $actor instanceof User ? $actor->getKey() : null,
            'created_at' => now(),
        ]);
    }

    /**
     * @param  Collection<int, SocialAccount>  $accounts
     */
    private function syncTargets(Post $post, Collection $accounts): void
    {
        $wanted = $accounts->keyBy(fn (SocialAccount $account) => $account->getKey());
        $existing = $post->targets()->get()->keyBy(fn (PostTarget $target) => $target->social_account_id);

        foreach ($existing as $accountId => $target) {
            if ($wanted->has($accountId)) {
                // Kept. Its schedule follows the post's.
                $target->forceFill(['scheduled_at' => $post->scheduled_at])->save();

                continue;
            }

            /*
             | Removed by the author. Only ever a target that has not gone out
             | -- a published row is the record that content exists on a
             | network, and deleting it would lose the publication history
             | while the post itself stays on the feed.
             */
            if (in_array($target->status, [TargetStatus::Published, TargetStatus::Processing], true)) {
                continue;
            }

            $target->delete();
        }

        foreach ($wanted as $accountId => $account) {
            if ($existing->has($accountId)) {
                continue;
            }

            $target = new PostTarget;
            $target->tenant_id = $post->tenant_id;
            $target->post_id = $post->getKey();
            $target->social_account_id = $account->getKey();
            $target->provider_key = $account->provider_key;
            $target->scheduled_at = $post->scheduled_at;
            $target->max_attempts = (int) config('publishing.max_attempts', 3);
            $target->idempotency_key = hash('sha256', $post->getKey().':'.$account->getKey().':'.Str::ulid());
            $target->save();
        }
    }

    /**
     * @param  Collection<int, Media>  $media
     */
    private function syncMedia(Post $post, Collection $media): void
    {
        /*
         | Replaced wholesale rather than diffed, because the ORDER is the
         | thing being edited as much as the membership: a carousel is a
         | sequence the author arranged, and sort_order is the only record of
         | that intent.
         */
        DB::table('post_media')->where('post_id', $post->getKey())->delete();

        foreach ($media->values() as $index => $item) {
            DB::table('post_media')->insert([
                'tenant_id' => $post->tenant_id,
                'post_id' => $post->getKey(),
                'media_id' => $item->getKey(),
                'sort_order' => $index,
                'role' => 'primary',
            ]);
        }
    }
}
