<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Media\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The one image URL a social network can fetch.
 *
 * Instagram will not take image bytes for a post: it takes an `image_url` and
 * fetches it from its own servers, with no session and no headers we control.
 * Every other media route in this application requires a signed-in agency user
 * or portal user, and Meta is neither.
 *
 * WHAT KEEPS THIS SAFE
 *
 *   - The signature is the authorisation, and it covers the expiry, so neither
 *     the media nor the deadline can be edited.
 *   - It is short-lived by configuration -- a provider fetches within seconds
 *     of being handed the URL, and anything longer is a public link to a
 *     client's unpublished creative left lying about.
 *   - The route takes the ULID, so a sequential id cannot be walked.
 *   - Only media that is ready. A file still processing, or soft-deleted by
 *     retention, is a 404 like anything else.
 *   - noindex, and no directory listing: one file, by signed reference, or
 *     nothing.
 *
 * This is the second unauthenticated view of tenant data in the application,
 * after the report share link, and it is built to the same rules: minted
 * deliberately, one at a time, with an expiry attached.
 */
final class ProviderMediaController extends Controller
{
    public function __invoke(Request $request, string $media): StreamedResponse
    {
        /*
         | withoutGlobalScopes: this request arrives from a provider's network
         | with no session and therefore no tenant. The signature already
         | proved the caller was given this exact URL by us, which is the
         | authorisation -- the scope would simply hide every row.
         */
        $file = Media::query()
            ->withoutGlobalScopes()
            ->where('ulid', $media)
            ->first();

        // 404 for missing, unusable and purged alike. A provider gets no more
        // information from us than a stranger would.
        abort_if($file === null || ! $file->isUsable(), 404);

        $disk = Storage::disk($file->disk);

        abort_unless($disk->exists($file->path), 404);

        return $disk->response(
            $file->path,
            $file->original_name,
            [
                // The sniffed mime from upload, never a client-sent header.
                'Content-Type' => $file->mime_type,
                'X-Content-Type-Options' => 'nosniff',

                /*
                 | no-store, unlike the portal route.
                 |
                 | That one is cached privately for a signed-in person looking
                 | at their own library. This one is fetched once by a machine
                 | and the URL dies minutes later; anything cached along the
                 | way outlives the permission that produced it.
                 */
                'Cache-Control' => 'private, no-store',
                'X-Robots-Tag' => 'noindex, nofollow',
            ],
            'inline',
        );
    }
}
