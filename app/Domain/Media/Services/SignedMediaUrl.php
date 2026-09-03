<?php

declare(strict_types=1);

namespace App\Domain\Media\Services;

use App\Domain\Media\Models\Media;
use Illuminate\Support\Facades\URL;

/**
 * Mints the short-lived URL a browser uses to fetch one media file.
 *
 * Serves both surfaces, which is why it is not named for either: `for()` signs
 * the portal route and `forAgency()` the agency one. What differs is only which
 * route does the authorising -- the signing and the TTL are identical, and a
 * security parameter should be set once.
 *
 * A class rather than a Blade helper because the TTL is a security parameter,
 * and a value that matters should be set in one place rather than typed into
 * each template that happens to need a picture.
 */
final class SignedMediaUrl
{
    public function for(Media $media, ?int $seconds = null): string
    {
        return $this->sign('portal.media.show', $media, $seconds);
    }

    /**
     * The agency-side equivalent.
     *
     * A separate route because the two surfaces authorise completely
     * differently -- a policy and a permission on one side, brand assignment
     * plus workflow stage on the other.
     */
    public function forAgency(Media $media, ?int $seconds = null): string
    {
        return $this->sign('agency.media.file', $media, $seconds);
    }

    private function sign(string $route, Media $media, ?int $seconds): string
    {
        /*
         | A signed route, NOT Media::temporaryUrl().
         |
         | temporaryUrl() delegates to the filesystem driver, and the `local`
         | driver -- the default here, and what shared hosting will run --
         | cannot produce one: it throws "This driver does not support creating
         | temporary URLs". The signed route works on every disk because the
         | application does the signing and streams the bytes itself.
         |
         | The trade-off is that bytes pass through PHP. On S3 this would be
         | worth revisiting so large video is served directly by the object
         | store rather than through the web process.
         */
        return URL::temporarySignedRoute(
            $route,
            now()->addSeconds($seconds ?? (int) config('media.signed_url_ttl', 300)),
            ['media' => $media->getRouteKey()],
        );
    }
}
