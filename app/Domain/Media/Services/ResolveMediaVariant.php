<?php

declare(strict_types=1);

namespace App\Domain\Media\Services;

use App\Domain\Media\Models\Media;

/**
 * Chooses which file on disk answers a media request.
 *
 * The variant name arrives from the URL. It is used only as a KEY into the
 * variants the job recorded on the row -- never joined onto a path, never
 * passed to the filesystem. So the worst a tampered name can do is miss, and a
 * miss falls back to the original rather than erroring: a thumbnail that has
 * not been generated yet should show the picture, not a broken tile.
 *
 * Shared by both surfaces so the agency library and the client portal resolve
 * identically. Two copies of this would drift, and the drift would be one
 * surface serving 4 MB originals into 320px tiles without anyone noticing.
 */
final class ResolveMediaVariant
{
    /**
     * @return array{path: string, mime: string}
     */
    public function resolve(Media $media, ?string $variant): array
    {
        $original = ['path' => $media->path, 'mime' => $media->mime_type];

        if ($variant === null || $variant === '') {
            return $original;
        }

        $variants = (array) ($media->variants ?? []);

        $path = $variants[$variant] ?? null;

        if (! is_string($path) || $path === '') {
            return $original;
        }

        // Variants are always WebP -- the job re-encodes every one of them --
        // so the type is a fact about how they are written, not a guess from
        // the name.
        return ['path' => $path, 'mime' => 'image/webp'];
    }
}
