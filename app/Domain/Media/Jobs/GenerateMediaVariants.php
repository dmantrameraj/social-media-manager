<?php

declare(strict_types=1);

namespace App\Domain\Media\Jobs;

use App\Domain\Billing\Entitlements\EntitlementResolver;
use App\Domain\Media\Enums\MediaStatus;
use App\Domain\Media\Models\Media;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageManagerInterface;
use RuntimeException;
use Throwable;

/**
 * Turns an uploaded image into the derivatives the product actually serves.
 *
 * This is the step that moves an image from `processing` to `ready`, and until
 * it existed nothing did: StoreMediaService marks every image `processing`, and
 * `ready` is what the composer offers, what MediaStatus::isUsable() requires and
 * what publishing re-checks. So every image ever uploaded was invisible to the
 * composer and unpublishable -- the library UI, alt text capture and the portal
 * previews were all built on rows that could never leave the waiting room.
 *
 * Re-encoding is a security control as much as a size one: decoding to a raster
 * and writing a fresh file discards EXIF (location data included), colour
 * profiles and anything smuggled in a trailing segment, so what gets served is
 * only pixels.
 */
final class GenerateMediaVariants implements ShouldQueue
{
    use Queueable;

    /**
     * Three attempts, then failed() marks the row.
     *
     * Not retried indefinitely: a corrupt upload fails identically every time,
     * and a row stuck retrying looks exactly like one that is merely slow.
     */
    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public readonly int $mediaId)
    {
        $this->onQueue((string) config('media.queue', 'media'));
    }

    public function handle(EntitlementResolver $entitlements): void
    {
        /*
         | acrossTenants because a queue worker has no tenant context -- there is
         | no request and no session to resolve one from. The id came from this
         | application when the row was written, never from user input, so it
         | cannot be pointed at another tenant's media.
         */
        $media = Media::query()->acrossTenants()->find($this->mediaId);

        /*
         | Deleted between upload and processing is ordinary rather than an
         | error: the queue is asynchronous and people change their minds.
         | Returning quietly avoids a failed job for something nobody did wrong.
         */
        if ($media === null || ! $media->isImage()) {
            return;
        }

        // Idempotent. A retry after a partial run, or a redelivered message,
        // must not double-count bytes or rewrite a row already finished.
        if ($media->status === MediaStatus::Ready) {
            return;
        }

        $source = Storage::disk($media->disk)->get($media->path);

        if ($source === null) {
            throw new RuntimeException("Media {$media->getKey()} has no readable source file.");
        }

        $manager = ImageManager::usingDriver(new Driver);

        $dimensions = $manager->decodeBinary($source);
        $media->width = $dimensions->width();
        $media->height = $dimensions->height();

        $variants = [];
        $bytes = 0;

        foreach ((array) config('media.variants', []) as $name => $spec) {
            $written = $this->writeVariant($media, $manager, $source, (string) $name, (array) $spec);

            if ($written === null) {
                continue;
            }

            $variants[$name] = $written['path'];
            $bytes += $written['bytes'];
        }

        $media->variants = $variants;
        // Named explicitly rather than taken from the first variant, so a later
        // config change to the variant list cannot silently blank the thumbnail
        // every list view renders.
        $media->thumbnail_path = $variants['thumb'] ?? null;
        $media->variant_bytes = $bytes;
        $media->status = MediaStatus::Ready;
        $media->save();

        // Derivatives count against the quota, so the cached figure is stale the
        // moment they land.
        $entitlements->forget($media->tenant, 'storage.max_bytes');
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array{path: string, bytes: int}|null
     */
    private function writeVariant(
        Media $media,
        ImageManagerInterface $manager,
        string $source,
        string $name,
        array $spec,
    ): ?array {
        $width = (int) ($spec['width'] ?? 0);
        $height = (int) ($spec['height'] ?? 0);

        if ($width < 1 || $height < 1) {
            return null;
        }

        // Decoded per variant rather than once and reused: the modifiers mutate
        // the image in place, so a shared instance would scale the preview down
        // from the already-shrunken thumbnail.
        $image = $manager->decodeBinary($source);

        /*
         | scaleDown, not scale: an image smaller than the variant box is left
         | alone rather than enlarged. Upscaling invents detail, costs bytes and
         | makes a small logo look worse than the original it came from.
         */
        $image->scaleDown($width, $height);

        // WebP regardless of input: materially smaller at the same quality, and
        // strip discards the metadata the original carried. The upload itself is
        // untouched and remains what a download returns.
        $encoded = $image->encode(new WebpEncoder(quality: 82, strip: true));

        $path = $this->variantPath($media, $name);

        Storage::disk($media->disk)->put($path, $encoded->toString());

        return ['path' => $path, 'bytes' => $encoded->size()];
    }

    /**
     * Derived from the original's server-generated path, so a variant inherits
     * its tenant/brand directory and can never be addressed by user input.
     */
    private function variantPath(Media $media, string $name): string
    {
        $directory = trim(str_replace('\\', '/', dirname($media->path)), './');
        $stem = pathinfo($media->path, PATHINFO_FILENAME);

        return sprintf('%s/variants/%s-%s.webp', $directory, $stem, $name);
    }

    /**
     * A failed image must not sit in `processing` for ever.
     *
     * `processing` reads as "nearly there" in every list view, so a permanent one
     * is a file the user waits on indefinitely. `failed` is visible, and it also
     * stops the row counting as usable.
     */
    public function failed(?Throwable $e): void
    {
        $media = Media::query()->acrossTenants()->find($this->mediaId);

        if ($media === null || $media->status === MediaStatus::Ready) {
            return;
        }

        $media->status = MediaStatus::Failed;
        $media->save();

        // Identified by id alone. Paths and original filenames are user data and
        // this line lands in a log that is not access-controlled the way the
        // media library is.
        Log::warning('Media variant generation failed.', [
            'media_id' => $this->mediaId,
            'exception' => $e?->getMessage(),
        ]);
    }
}
