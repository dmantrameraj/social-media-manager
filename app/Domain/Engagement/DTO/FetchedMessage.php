<?php

declare(strict_types=1);

namespace App\Domain\Engagement\DTO;

use App\Domain\Engagement\Enums\MessageDirection;
use Illuminate\Support\Carbon;

/** One message inside a fetched conversation. */
final class FetchedMessage
{
    public function __construct(
        public string $externalMessageId,
        public string $body,
        public MessageDirection $direction,
        public ?string $authorName = null,
        public ?Carbon $postedAt = null,
    ) {}
}
