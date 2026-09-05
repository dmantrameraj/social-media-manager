<?php

declare(strict_types=1);

namespace App\Domain\Media\Services;

use App\Domain\Media\Models\Media;
use App\Domain\Social\DTO\MediaItem;
use Illuminate\Support\Facades\URL;

/**
 * A URL a social network can fetch an image from.
 *
 * Meta does not accept image bytes for an Instagram post: it takes an
 * `image_url` and fetches it itself, from its own servers, unauthenticated.
 * Our media lives on a PRIVATE disk behind signed routes that require a signed
 * -in agency user or portal user, and neither of those is Meta.
 *
 * So this is a third signer, and deliberately a separate one:
 *
 *   - The signature IS the authorisation. There is no session, because the
 *     caller is a machine in someone else's data centre.
 *   - It is short-lived. A provider fetches within seconds of being told to;
 *     an hour would be a public link to a client's unpublished creative left
 *     lying around for an hour.
 *   - It is per-media and opaque. The route takes the ULID, so a sequential id
 *     cannot be walked, and the signature covers the expiry so neither can be
 *     edited.
 *
 * The same reasoning as the report share link, and the same shape: the only
 * unauthenticated views of tenant data in this application are minted
 * deliberately, one at a time, with an expiry attached.
 */
final class ProviderMediaUrl
{
    /**
     * @return string|null null when the file cannot be offered, which the
     *                     caller must treat as "do not publish this"
     */
    public function for(MediaItem $item): ?string
    {
        $media = Media::query()->withoutGlobalScopes()->find($item->id);

        if ($media === null || ! $media->isUsable()) {
            return null;
        }

        return URL::temporarySignedRoute(
            'media.provider',
            now()->addSeconds((int) config('media.provider_url_ttl', 600)),
            ['media' => $media->ulid],
        );
    }

    /**
     * Whether a provider could actually reach us.
     *
     * A signed URL on http://localhost is valid and useless: Meta fetches from
     * its own network and will simply fail. Saying so before publishing turns
     * a baffling provider error into a sentence somebody can act on.
     */
    public function isReachable(): bool
    {
        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

        return $host !== ''
            && $host !== 'localhost'
            && $host !== '127.0.0.1'
            && ! str_ends_with($host, '.test')
            && ! str_ends_with($host, '.local');
    }
}
