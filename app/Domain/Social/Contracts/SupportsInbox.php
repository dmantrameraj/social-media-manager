<?php

declare(strict_types=1);

namespace App\Domain\Social\Contracts;

use App\Domain\Engagement\DTO\FetchedThread;
use App\Domain\Social\Exceptions\ProviderException;
use App\Domain\Social\Models\SocialAccount;
use Illuminate\Support\Collection;

/**
 * Phase 7. A provider whose API exposes conversations.
 *
 * Optional, like every other capability interface here, because most networks
 * expose only some of this and several expose none. The roadmap says "where
 * provider APIs permit", and `instanceof` is how that permission is checked --
 * a single fat interface would force every adapter to implement methods it
 * cannot honour, and the usual result is a stub that returns an empty array
 * and looks like a quiet customer rather than a missing feature.
 *
 * Two capabilities, deliberately separate. Most platforms let you reply to a
 * comment indefinitely but restrict direct messages to a window after the
 * person last wrote, so an adapter can honestly support reading a conversation
 * without being able to answer it.
 */
interface SupportsInbox
{
    /**
     * Conversations on this account, newest activity first.
     *
     * @param  string|null  $cursor  provider pagination token from a previous call
     * @return Collection<int, FetchedThread>
     */
    public function fetchThreads(SocialAccount $account, ?string $cursor = null): Collection;

    /**
     * Send a reply.
     *
     * Returns the provider's id for the created message, which is what makes
     * the reply survive the next sync as one message rather than reappearing
     * as a second copy.
     *
     * @throws ProviderException
     */
    public function replyToThread(
        SocialAccount $account,
        string $externalThreadId,
        string $body,
    ): string;

    /**
     * Whether this thread can still be replied to right now.
     *
     * Asked before a reply box is offered, because the alternative is letting
     * somebody write a careful answer to a customer and discover on submit
     * that the window closed.
     */
    public function canReplyTo(SocialAccount $account, string $externalThreadId): bool;
}
