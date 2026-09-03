<?php

declare(strict_types=1);

namespace App\Domain\Publishing\Services;

use App\Domain\Media\Models\Media;
use App\Domain\Publishing\Models\PostTarget;
use App\Domain\Social\DTO\MediaItem;
use App\Domain\Social\DTO\PublishPayload;

/**
 * Turns a stored target into the DTO a provider actually receives.
 *
 * Nothing built one of these before: the publishing engine was fully written
 * and tested, but every PublishPayload in the codebase was constructed inside
 * a test. There was no path from a scheduled row to a provider call, which is
 * why nothing ever published.
 *
 * A service rather than a method on PostTarget, because it reaches across the
 * post, its media and the target's overrides -- and because the provider layer
 * must keep receiving plain DTOs it cannot use to mutate domain state.
 */
final class BuildPublishPayloadService
{
    public function execute(PostTarget $target): PublishPayload
    {
        $post = $target->post;

        return new PublishPayload(
            // The override if there is one, else the master body. Per-platform
            // rewrites are the whole reason targets carry their own copy.
            body: $target->effectiveBody(),
            contentType: (string) ($post->content_type ?? 'text'),
            media: $this->mediaFor($target),
            link: $post->link_url,
            firstComment: $post->first_comment,
            /*
             | Target meta wins over the post's. A per-platform field -- a
             | YouTube title, an Instagram audience -- is set on the target
             | precisely because it should not apply everywhere.
             */
            meta: array_merge(
                (array) ($target->meta ?? []),
                (array) ($target->meta_override ?? []),
            ),
            scheduledAt: $target->scheduled_at,
            /*
             | Stable across retries of the same target, which is what makes a
             | redelivered job safe: a provider with native idempotency rejects
             | the duplicate, and one without it is fingerprinted on this.
             */
            idempotencyKey: $target->idempotency_key,
        );
    }

    /**
     * The post's media, in the order somebody arranged it.
     *
     * @return list<MediaItem>
     */
    private function mediaFor(PostTarget $target): array
    {
        $items = [];

        foreach ($target->post->media as $media) {
            /*
             | Media attached to ONE target is not sent to the others. The
             | pivot's post_target_id is null for shared attachments and set
             | when somebody chose a different image for a single platform.
             */
            $onlyFor = $media->pivot->post_target_id ?? null;

            if ($onlyFor !== null && (int) $onlyFor !== $target->getKey()) {
                continue;
            }

            $items[] = $this->toItem($media);
        }

        return $items;
    }

    private function toItem(Media $media): MediaItem
    {
        return new MediaItem(
            id: $media->getKey(),
            path: $media->path,
            disk: $media->disk,
            mimeType: $media->mime_type,
            sizeBytes: $media->size_bytes,
            width: $media->width,
            height: $media->height,
            durationSeconds: $media->duration_seconds,
            role: $media->pivot->role ?? null,
            // Carried through so the published post is described on the
            // platform, not only in this application's own preview.
            altText: $media->alt_text,
        );
    }
}
