<?php

declare(strict_types=1);

namespace App\Domain\Social\DTO;

/**
 * One media attachment, resolved to something a provider can actually fetch.
 *
 * Carries a local path rather than an Eloquent Media model so provider
 * adapters stay database-free and testable.
 */
final readonly class MediaItem
{
    public function __construct(
        public int $id,
        public string $path,
        public string $disk,
        public string $mimeType,
        public int $sizeBytes,
        public ?int $width = null,
        public ?int $height = null,
        public ?int $durationSeconds = null,
        public ?string $role = null,

        /*
         | Carried to the provider so the published post is described, not just
         | the preview in this application.
         |
         | Adapters must clamp this to their own platform's ceiling -- the
         | limits differ per network and are [VERIFY] against current provider
         | documentation. The column allows 1000 so the database is never the
         | thing that truncates.
         */
        public ?string $altText = null,
    ) {}

    public function hasAltText(): bool
    {
        return trim((string) $this->altText) !== '';
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mimeType, 'image/');
    }

    public function isVideo(): bool
    {
        return str_starts_with($this->mimeType, 'video/');
    }

    /** Aspect ratio, or null when dimensions are unknown. */
    public function aspectRatio(): ?float
    {
        if ($this->width === null || $this->height === null || $this->height === 0) {
            return null;
        }

        return $this->width / $this->height;
    }
}
