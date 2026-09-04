<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Services;

use App\Domain\Audit\Enums\ActorType;
use App\Domain\Engagement\Enums\DeliveryStatus;
use App\Domain\Engagement\Enums\InboxStatus;
use App\Domain\Engagement\Enums\MessageDirection;
use App\Domain\Engagement\Models\InboxMessage;
use App\Domain\Engagement\Models\InboxThread;
use App\Domain\Identity\Models\User;
use App\Domain\Social\Contracts\SupportsInbox;
use App\Domain\Social\Exceptions\ProviderException;
use App\Domain\Social\ProviderRegistry;
use Illuminate\Support\Facades\Log;

/**
 * Sends a reply, and is honest about whether it arrived.
 *
 * The message row is written BEFORE the provider is called and marked pending,
 * then settled by the outcome. The alternative -- call first, store on success
 * -- loses the reply entirely when the process dies mid-call, and the person
 * who wrote it has no way to know whether the customer received it.
 *
 * A failed reply is kept, not deleted. Somebody spent time writing it, and
 * showing it as unsent lets them retry or copy it elsewhere; silently
 * discarding it means the customer is ignored and nobody notices.
 */
final class ReplyToThreadService
{
    public function __construct(private readonly ProviderRegistry $providers) {}

    /**
     * A public reply, sent to the platform.
     */
    public function reply(InboxThread $thread, User $actor, string $body): InboxMessage
    {
        $message = $this->store(
            $thread,
            $actor,
            $body,
            internal: false,
            status: DeliveryStatus::Pending,
        );

        $provider = $this->providers->for($thread->provider_key);

        if (! $provider instanceof SupportsInbox) {
            /*
             | No adapter in this deployment can send it. Marked failed rather
             | than left pending for ever: "sending" that never resolves is the
             | state that makes somebody assume it went.
             */
            $this->settle($message, DeliveryStatus::Failed, 'unsupported');

            return $message->fresh();
        }

        try {
            $externalId = $provider->replyToThread(
                $thread->socialAccount,
                $thread->external_thread_id,
                $body,
            );
        } catch (ProviderException $e) {
            $this->settle($message, DeliveryStatus::Failed, $e->errorClass->value);

            Log::warning('An inbox reply was refused.', [
                'inbox_thread_id' => $thread->getKey(),
                'provider' => $thread->provider_key,
                'error_class' => $e->errorClass->value,
            ]);

            return $message->fresh();
        }

        /*
         | The external id is what makes this survive the next sync as one
         | message rather than reappearing as a second copy of itself.
         */
        $message->forceFill([
            'external_message_id' => $externalId,
            'delivery_status' => DeliveryStatus::Delivered->value,
            'last_error_code' => null,
            'posted_at' => now(),
        ])->save();

        /*
         | Answered, so the thread is waiting on them rather than on us. Set
         | only on a delivered reply -- moving it on a failure would hide a
         | customer nobody actually answered.
         */
        $thread->forceFill(['status' => InboxStatus::Pending->value])->save();

        return $message->fresh();
    }

    /**
     * A note for colleagues, which never leaves the building.
     *
     * Stored in the same thread as the conversation it is about, exactly as
     * PostComment does for a post: notes kept elsewhere are notes nobody
     * reads, because they are not where the conversation is.
     */
    public function note(InboxThread $thread, User $actor, string $body): InboxMessage
    {
        return $this->store(
            $thread,
            $actor,
            $body,
            internal: true,
            // It was never going anywhere, so it is settled on arrival.
            status: DeliveryStatus::Delivered,
        );
    }

    private function store(
        InboxThread $thread,
        User $actor,
        string $body,
        bool $internal,
        DeliveryStatus $status,
    ): InboxMessage {
        $message = new InboxMessage;

        $message->forceFill([
            'tenant_id' => $thread->tenant_id,
            'inbox_thread_id' => $thread->getKey(),
            'direction' => MessageDirection::Outbound->value,
            /*
             | Derived from the caller's intent, never from request input.
             | An internal note sent as a public reply is a private remark
             | delivered to a customer.
             */
            'is_internal' => $internal,
            // The discriminator, matching audit_logs and post_approvals, which
            // is what makes the trails joinable.
            'author_type' => ActorType::User->value,
            'author_id' => $actor->getKey(),
            'author_name' => $actor->name,
            'body' => $body,
            'delivery_status' => $status->value,
            'posted_at' => now(),
        ])->save();

        return $message;
    }

    private function settle(InboxMessage $message, DeliveryStatus $status, ?string $code): void
    {
        $message->forceFill([
            'delivery_status' => $status->value,
            'last_error_code' => $code,
        ])->save();
    }
}
