<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Access\Policies\TenantScopedPolicy;
use App\Domain\Identity\Models\User;
use App\Domain\Media\Models\Media;

final class MediaPolicy extends TenantScopedPolicy
{
    protected function permissionPrefix(): string
    {
        return 'media';
    }

    public function create(User $user): bool
    {
        // The media catalogue uses 'upload' rather than 'create'.
        return $user->can('media.upload');
    }

    /**
     * Downloading is a read, but it hands over the actual bytes, so it is
     * gated explicitly rather than inheriting view(). The signed URL is only
     * ever issued after this passes.
     */
    public function download(User $user, Media $media): bool
    {
        return $this->allows($user, $media, 'view');
    }
}
