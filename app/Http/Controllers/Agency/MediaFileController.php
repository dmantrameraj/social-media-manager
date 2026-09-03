<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agency;

use App\Domain\Media\Models\Media;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a media file to agency staff.
 *
 * The agency counterpart to Portal\MediaController, and deliberately a
 * separate class rather than one polymorphic route: the two surfaces answer
 * "may this person see this file?" with completely different questions -- a
 * policy and a permission here, brand assignment plus workflow stage there.
 * One controller branching on the guard is how the looser branch eventually
 * answers for the stricter one.
 *
 * Three checks, same shape as the portal:
 *   1. a valid signature (the `signed` middleware), so ids cannot be walked
 *   2. an authenticated agency session with a resolved tenant
 *   3. MediaPolicy@download, which is tenant, brand-assignment and permission
 */
final class MediaFileController extends Controller
{
    public function __invoke(Request $request, Media $media): StreamedResponse
    {
        /*
         | download() rather than view(): it hands over the actual bytes, and
         | the policy draws that distinction on purpose. Route-model binding has
         | already applied the tenant scope, so a foreign row 404s before
         | reaching here.
         */
        $request->user()->can('download', $media) || abort(404);

        $disk = Storage::disk($media->disk);

        // A row whose file has gone is a 404, not a 500: retention can purge a
        // file while the row still references it.
        abort_unless($disk->exists($media->path), 404);

        return $disk->response(
            $media->path,
            $media->original_name,
            [
                // The mime type established by sniffing at upload, never a
                // client-declared header. nosniff stops a browser deciding an
                // "image" is HTML and running it in this origin.
                'Content-Type' => $media->mime_type,
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, max-age=300, no-transform',
            ],
            'inline',
        );
    }
}
