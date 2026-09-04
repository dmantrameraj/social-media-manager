<?php

declare(strict_types=1);

namespace App\Domain\Engagement\DTO;

use App\Domain\Engagement\Enums\InboxKind;
use Illuminate\Support\Carbon;

/**
 * One conversation as a provider described it.
 *
 * Adapters return these; nothing above them knows a provider's field names.
 * The mapping from a platform's vocabulary into this shape is an adapter's
 * job, verified against that platform's live documentation.
 */
final class FetchedThread
{
    /**
     * @param  list<FetchedMessage>  $messages
     */
    public function __construct(
        public string $externalThreadId,
        public InboxKind $kind,
        public array $messages = [],
        public ?string $participantName = null,
        public ?string $participantExternalId = null,
        public ?Carbon $lastMessageAt = null,
        /**
         * Set only when the provider tells us which of our posts this is
         * about. Guessing from timing or text would attach a stranger's
         * comment to a client's campaign.
         */
        public ?string $externalPostId = null,
    ) {}
}
