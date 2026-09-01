<?php

declare(strict_types=1);

namespace App\Domain\Social\DTO;

use Illuminate\Support\Carbon;

/**
 * Everything a provider needs to publish one post to one account.
 *
 * A plain DTO, not an Eloquent model: provider adapters must be unit-testable
 * without a database, and must not be able to mutate domain state.
 */
final readonly class PublishPayload
{
    /**
     * @param  list<MediaItem>  $media
     * @param  array<string, mixed>  $meta  per-platform fields: title, privacy,
     *                                      thumbnail, poll options, and so on
     */
    public function __construct(
        public string $body,
        public string $contentType,
        public array $media = [],
        public ?string $link = null,
        public ?string $firstComment = null,
        public array $meta = [],
        public ?Carbon $scheduledAt = null,
        /**
         * Stable across retries of the same target. Passed to providers that
         * support native idempotency keys, and used as the fingerprint for
         * those that do not.
         */
        public ?string $idempotencyKey = null,
    ) {}

    public function hasMedia(): bool
    {
        return $this->media !== [];
    }

    public function mediaCount(): int
    {
        return count($this->media);
    }

    public function metaValue(string $key, mixed $default = null): mixed
    {
        return $this->meta[$key] ?? $default;
    }
}
