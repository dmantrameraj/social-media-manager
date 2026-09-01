<?php

declare(strict_types=1);

namespace App\Domain\Access\Services;

use App\Domain\Access\PermissionCatalogue;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Projects config/permissions.php into the permissions table.
 *
 * Idempotent: safe to run on every deploy. Permissions are global rows scoped
 * only by guard -- spatie's team column lives on roles, not permissions, so a
 * permission is shared across tenants while the ROLE that grants it is not.
 */
final class SyncPermissionCatalogueService
{
    public function __construct(private readonly PermissionCatalogue $catalogue) {}

    /**
     * @return array{created: int, existing: int}
     */
    public function execute(): array
    {
        $wanted = [
            $this->catalogue->tenantGuard() => $this->catalogue->webGuardPermissions(),
            $this->catalogue->portalGuard() => $this->catalogue->portalPermissions(),
        ];

        $created = 0;
        $existing = 0;

        DB::transaction(function () use ($wanted, &$created, &$existing): void {
            foreach ($wanted as $guard => $permissions) {
                foreach ($permissions as $name) {
                    $permission = Permission::query()->firstOrCreate([
                        'name' => $name,
                        'guard_name' => $guard,
                    ]);

                    $permission->wasRecentlyCreated ? $created++ : $existing++;
                }
            }
        });

        // spatie caches the permission map aggressively; a stale cache after a
        // sync means new permissions appear not to exist.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return ['created' => $created, 'existing' => $existing];
    }

    /**
     * Permissions present in the database but no longer in the catalogue.
     *
     * Deliberately reported rather than deleted: removing a permission revokes
     * access for everyone holding it, which should be a explicit decision, not
     * a side effect of a deploy.
     *
     * @return list<string>
     */
    public function orphans(): array
    {
        $known = array_merge(
            $this->catalogue->webGuardPermissions(),
            $this->catalogue->portalPermissions(),
        );

        return Permission::query()
            ->whereNotIn('name', $known)
            ->pluck('name')
            ->all();
    }
}
