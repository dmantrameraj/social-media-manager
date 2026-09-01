<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Access\Policies\TenantScopedPolicy;
use App\Domain\Identity\Models\User;
use App\Domain\Media\Models\MediaFolder;
use Illuminate\Database\Eloquent\Model;

final class MediaFolderPolicy extends TenantScopedPolicy
{
    protected function permissionPrefix(): string
    {
        return 'media';
    }

    public function create(User $user): bool
    {
        return $user->can('media.manage_folders');
    }

    public function update(User $user, Model $model): bool
    {
        return $this->canReach($user, $model)
            && $user->can('media.manage_folders')
            && ! $this->isSystemFolder($model);
    }

    /**
     * Seeded folders (Logos, Products, ...) are structural: other features
     * reference them by system_key, so renaming or deleting one would break
     * those references silently.
     */
    public function delete(User $user, Model $model): bool
    {
        return $this->canReach($user, $model)
            && $user->can('media.manage_folders')
            && ! $this->isSystemFolder($model);
    }

    private function isSystemFolder(Model $model): bool
    {
        return $model instanceof MediaFolder && $model->isSystemFolder();
    }
}
