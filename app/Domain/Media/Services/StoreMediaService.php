<?php

declare(strict_types=1);

namespace App\Domain\Media\Services;

use App\Domain\Billing\Entitlements\EntitlementResolver;
use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Media\Enums\MediaStatus;
use App\Domain\Media\Exceptions\MediaRejected;
use App\Domain\Media\Models\Media;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Accepts an upload into a brand's media library.
 *
 * Every check here treats the client as hostile: the declared MIME type, the
 * declared size and the original filename are all attacker-controlled.
 * See docs/10-SECURITY.md §6.
 */
final class StoreMediaService
{
    public function __construct(private readonly EntitlementResolver $entitlements) {}

    public function execute(
        Customer $customer,
        User $actor,
        UploadedFile $file,
        ?int $folderId = null,
        ?string $altText = null,
    ): Media {
        $this->assertAcceptable($file);

        $size = (int) $file->getSize();
        $this->assertStorageAvailable($customer, $size);

        // Server-generated name and path. The original filename is metadata
        // only -- using it on disk invites traversal and collision.
        $extension = Str::lower($file->getClientOriginalExtension());
        $disk = (string) config('media.disk', 'local');
        $directory = sprintf('media/%d/%d', $customer->tenant_id, $customer->getKey());
        $filename = Str::ulid()->toString().'.'.$extension;

        return DB::transaction(function () use (
            $customer, $actor, $file, $folderId, $size, $disk, $directory, $filename, $extension, $altText
        ): Media {
            $path = Storage::disk($disk)->putFileAs($directory, $file, $filename);

            if ($path === false) {
                throw new MediaRejected('The file could not be stored.');
            }

            $media = new Media;
            $media->tenant_id = $customer->tenant_id;
            $media->customer_id = $customer->getKey();
            $media->folder_id = $this->resolveFolderId($customer, $folderId);
            $media->disk = $disk;
            $media->path = $path;
            $media->original_name = Str::limit($file->getClientOriginalName(), 255, '');
            // Sniffed from content, never the client-declared type.
            $media->mime_type = (string) $file->getMimeType();
            $media->extension = $extension;
            $media->size_bytes = $size;
            $media->checksum = hash_file('sha256', $file->getRealPath()) ?: null;
            $media->uploaded_by_user_id = $actor->getKey();

            /*
             | Captured at upload, when the person who chose the file is still
             | looking at it. Asking later, in a bulk "fix your alt text" screen,
             | reliably produces "photo" and "image1".
             |
             | Trimmed to null rather than stored blank, so needsAltText() does
             | not have to treat whitespace as a description.
             */
            $media->alt_text = $this->normaliseAltText($altText);

            // Images still need variants generated on the media queue; other
            // types are immediately usable.
            $media->status = $media->isImage()
                ? MediaStatus::Processing
                : MediaStatus::Ready;

            $this->applyImageDimensions($media, $file);

            $media->save();

            $this->entitlements->forget($customer->tenant, 'storage.max_bytes');

            return $media;
        });
    }

    /**
     * @throws MediaRejected
     */
    private function assertAcceptable(UploadedFile $file): void
    {
        if (! $file->isValid()) {
            throw new MediaRejected('The upload did not complete successfully.');
        }

        $maxBytes = (int) config('media.max_upload_bytes');

        if ($file->getSize() > $maxBytes) {
            $mb = round($maxBytes / 1_048_576, 1);

            throw new MediaRejected("Files must be smaller than {$mb} MB.");
        }

        $extension = Str::lower($file->getClientOriginalExtension());

        if ($extension === '') {
            throw new MediaRejected('Files must have a file extension.');
        }

        // SVG is an XSS vector -- it can carry script and is served
        // same-origin. Off unless explicitly enabled with a sanitiser.
        if ($extension === 'svg' && ! config('media.allow_svg', false)) {
            throw new MediaRejected('SVG uploads are not permitted.');
        }

        if (! in_array($extension, (array) config('media.allowed_extensions', []), true)) {
            throw new MediaRejected("Files of type .{$extension} are not supported.");
        }

        // getMimeType() sniffs the file's contents; getClientMimeType() would
        // simply echo back what the client claimed.
        $mime = (string) $file->getMimeType();

        if (! in_array($mime, (array) config('media.allowed_mimes', []), true)) {
            throw new MediaRejected('That file type is not supported.');
        }

        // A .jpg carrying a PDF payload passes both lists individually; this
        // catches the mismatch between them.
        if (! $this->extensionMatchesMime($extension, $mime)) {
            throw new MediaRejected('The file contents do not match its extension.');
        }
    }

    private function extensionMatchesMime(string $extension, string $mime): bool
    {
        $expected = match ($extension) {
            'jpg', 'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'gif' => ['image/gif'],
            'webp' => ['image/webp'],
            'mp4' => ['video/mp4'],
            'mov' => ['video/quicktime'],
            'pdf' => ['application/pdf'],
            default => [],
        };

        return $expected === [] || in_array($mime, $expected, true);
    }

    private function assertStorageAvailable(Customer $customer, int $size): void
    {
        // Quota is checked before the bytes are written, not after -- a
        // post-hoc check has already consumed the disk.
        $this->entitlements->guard($customer->tenant, 'storage.max_bytes', $size);
    }

    /**
     * A folder id from a request is only honoured if it belongs to this brand.
     */
    /**
     * Blank, whitespace and over-long input all normalise to something safe.
     *
     * Null rather than '' because "has no description" and "has an empty
     * description" must not be different states -- a screen reader announces
     * an empty alt as though the image were decorative.
     */
    private function normaliseAltText(?string $altText): ?string
    {
        $trimmed = trim((string) $altText);

        return $trimmed === '' ? null : Str::limit($trimmed, 1000, '');
    }

    private function resolveFolderId(Customer $customer, ?int $folderId): ?int
    {
        if ($folderId === null) {
            return null;
        }

        return $customer->mediaFolders()
            ->whereKey($folderId)
            ->value('id');
    }

    private function applyImageDimensions(Media $media, UploadedFile $file): void
    {
        if (! $media->isImage()) {
            return;
        }

        $dimensions = @getimagesize($file->getRealPath());

        if ($dimensions !== false) {
            $media->width = (int) $dimensions[0];
            $media->height = (int) $dimensions[1];
        }
    }
}
