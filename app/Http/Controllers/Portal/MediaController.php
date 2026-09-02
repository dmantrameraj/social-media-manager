<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Domain\Customers\Services\PortalPostQuery;
use App\Domain\Media\Models\Media;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a media file to a client.
 *
 * Media lives on a PRIVATE disk and is never reachable by direct path, so the
 * bytes are streamed through here after three independent checks:
 *
 *   1. a valid signature (the `signed` middleware) -- proves the application
 *      minted this URL, so ids cannot be walked
 *   2. an authenticated portal session (`auth:customer`) -- a signature alone
 *      must not be enough, because a signed URL is shareable until it expires
 *   3. the file is attached to a post THIS client is allowed to see
 *
 * The third is the one that matters. Brand assignment alone would be too
 * loose: a client must not see everything in their brand's media library, only
 * what the agency actually put in a post and sent for review.
 */
final class MediaController extends Controller
{
    public function __construct(private readonly PortalPostQuery $posts) {}

    public function __invoke(Request $request, Media $media): StreamedResponse
    {
        $this->assertVisible($request, $media);

        $disk = Storage::disk($media->disk);

        // A row whose file has gone missing is a 404, not a 500. Media can be
        // purged by retention while a post still references it.
        abort_unless($disk->exists($media->path), 404);

        return $disk->response(
            $media->path,
            $media->original_name,
            [
                /*
                 | The stored mime_type, which was established by sniffing the
                 | file's contents at upload -- never the client-sent header.
                 |
                 | X-Content-Type-Options stops a browser sniffing its way to a
                 | different type: an "image" that a browser decides is HTML
                 | would execute in the application's own origin.
                 */
                'Content-Type' => $media->mime_type,
                'X-Content-Type-Options' => 'nosniff',

                // Private: this is one client's content behind a signed URL,
                // and it must not sit in a shared or proxy cache.
                'Cache-Control' => 'private, max-age=300, no-transform',
            ],

            /*
             | 'inline' so a PDF opens in the browser rather than being pushed
             | at the client as a download to manage.
             |
             | The disposition header is left to Laravel to build from the name
             | above, because makeDisposition() does the RFC 6266 encoding and
             | ASCII fallback. A hand-rolled `filename="..."` breaks the moment
             | a client uploads something with an accent or a CJK name in it.
             */
            'inline',
        );
    }

    /**
     * 404, never 403.
     *
     * Consistent with the rest of the portal: a client must not be able to
     * learn that a file exists by probing. Another tenant's media, another
     * brand's media, and media attached only to an unsent draft are all
     * indistinguishable from a file that was never there.
     */
    private function assertVisible(Request $request, Media $media): void
    {
        $user = $request->user('customer');

        abort_if($user === null, 404);

        // Tenant first: the media route is not tenant-scoped by middleware,
        // because there is no tenant context on the customer guard.
        abort_unless($media->tenant_id === $user->tenant_id, 404);

        abort_unless($user->canAccessCustomer($media->customer_id), 404);

        /*
         | The decisive check. Reuses PortalPostQuery so the definition of "a
         | post this client may see" lives in exactly one place -- a second
         | copy here would drift the first time the rules change.
         */
        $reachable = $this->posts->for($user)
            ->whereHas('media', fn ($query) => $query->whereKey($media->getKey()))
            ->exists();

        abort_unless($reachable, 404);
    }
}
