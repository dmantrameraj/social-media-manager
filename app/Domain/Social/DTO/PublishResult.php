<?php

declare(strict_types=1);

namespace App\Domain\Social\DTO;

use Illuminate\Support\Carbon;

/**
 * A successful publish.
 *
 * externalId is the idempotency anchor: once we hold it, a retry must never
 * create a second post.
 */
final readonly class PublishResult
{
    /** @param  array<string, mixed>  $raw */
    public function __construct(
        public string $externalId,
        public ?string $externalUrl = null,
        public ?Carbon $publishedAt = null,
        public array $raw = [],
    ) {}
}
