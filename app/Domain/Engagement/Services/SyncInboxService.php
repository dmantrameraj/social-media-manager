<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Services;

use App\Domain\Engagement\DTO\FetchedMessage;
use App\Domain\Engagement\DTO\FetchedThread;
use App\Domain\Engagement\Enums\DeliveryStatus;
use App\Domain\Engagement\Enums\InboxStatus;
use App\Domain\Engagement\Enums\MessageDirection;
use App\Domain\Engagement\Models\InboxMessage;
use App\Domain\Engagement\Models\InboxThread;
use App\Domain\Publishing\Models\PostTarget;
use App\Domain\Social\Contracts\SupportsInbox;
use App\Domain\Social\Models\SocialAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Brings one account's conversations up to date.
 *
 * Written to be run repeatedly and out of order, because that is how syncing
 * against somebody else's API actually behaves: pages arrive twice, a run
 * overlaps the previous one, and a message we already hold comes back looking
 * new. Everything here keys on the provider's own ids so a second pass changes
 * nothing.
 */
final class SyncInboxService
{
    /**
     * @return int how many messages were new
     */
    public function sync(SocialAccount $account, SupportsInbox $provider): int
    {
        $new = 0;

        foreach ($provider->fetchThreads($account) as $fetched) {
            $new += $this->syncThread($account, $fetched);
        }

        return $new;
    }

    private function syncThread(SocialAccount $account, FetchedThread $fetched): int
    {
        return DB::transaction(function () use ($account, $fetched): int {
            /*
             | Keyed on (account, external id) -- the same unique the table
             | carries. A re-sync must UPDATE a conversation, not create a
             | second copy that splits its history in two.
             */
            $thread = InboxThread::query()
                ->where('social_account_id', $account->getKey())
                ->where('external_thread_id', $fetched->externalThreadId)
                ->first() ?? new InboxThread;

            $existed = $thread->exists;

            $values = [
                'tenant_id' => $account->tenant_id,
                'customer_id' => $account->customer_id,
                'social_account_id' => $account->getKey(),
                'provider_key' => $account->provider_key,
                'kind' => $fetched->kind->value,
                'external_thread_id' => $fetched->externalThreadId,
                'participant_name' => $fetched->participantName,
                'participant_external_id' => $fetched->participantExternalId,
                'last_message_at' => $fetched->lastMessageAt,
                'last_synced_at' => now(),
            ];

            /*
             | Status and assignment are set ONCE, at creation. A sync must not
             | reopen a thread somebody closed or drop the colleague who owns
             | it -- that is the agency's state, not the provider's, and
             | overwriting it every few minutes would make the queue
             | unusable.
             */
            if (! $existed) {
                $values['status'] = InboxStatus::Open->value;
                $values['post_target_id'] = $this->resolveTarget($account, $fetched);
                $thread->ulid = (string) Str::ulid();
            }

            $thread->forceFill($values)->save();

            $new = 0;

            foreach ($fetched->messages as $message) {
                $new += $this->syncMessage($thread, $message) ? 1 : 0;
            }

            /*
             | A new inbound message reopens a closed thread. Somebody writing
             | again is a new conversation in every sense that matters, and
             | leaving it closed is how a customer gets ignored twice.
             */
            if ($existed && $new > 0 && $thread->status === InboxStatus::Closed) {
                $thread->forceFill(['status' => InboxStatus::Open->value])->save();
            }

            return $new;
        });
    }

    /** @return bool whether this message was new */
    private function syncMessage(InboxThread $thread, FetchedMessage $fetched): bool
    {
        $existing = InboxMessage::query()
            ->where('inbox_thread_id', $thread->getKey())
            ->where('external_message_id', $fetched->externalMessageId)
            ->first();

        if ($existing !== null) {
            /*
             | Already held. The body is deliberately NOT refreshed: platforms
             | allow editing, and silently rewriting what somebody replied to
             | would make the thread disagree with what the person actually
             | read when they answered it.
             */
            return false;
        }

        $message = new InboxMessage;

        $message->forceFill([
            'tenant_id' => $thread->tenant_id,
            'inbox_thread_id' => $thread->getKey(),
            'external_message_id' => $fetched->externalMessageId,
            'direction' => $fetched->direction->value,
            'is_internal' => false,
            'author_name' => $fetched->authorName,
            'body' => $fetched->body,
            // It arrived, so it was delivered. Only our own replies can be
            // pending or refused.
            'delivery_status' => DeliveryStatus::Delivered->value,
            'posted_at' => $fetched->postedAt ?? now(),
        ])->save();

        return $fetched->direction === MessageDirection::Inbound;
    }

    /**
     * Link the conversation to one of our posts, when the provider says so.
     *
     * Only on an exact external id. Matching by timing or text would attach a
     * stranger's comment to a client's campaign, and a wrong association in a
     * client report is worse than none.
     */
    private function resolveTarget(SocialAccount $account, FetchedThread $fetched): ?int
    {
        if ($fetched->externalPostId === null) {
            return null;
        }

        return PostTarget::query()
            ->where('social_account_id', $account->getKey())
            ->where('external_post_id', $fetched->externalPostId)
            ->value('id');
    }
}
