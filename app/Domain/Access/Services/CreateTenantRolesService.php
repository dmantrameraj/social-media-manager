<?php

declare(strict_types=1);

namespace App\Domain\Access\Services;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds a tenant's role templates.
 *
 * Roles carry team_id = tenant_id, so two agencies each own an independent
 * "Manager" role and one editing theirs cannot affect the other.
 *
 * The catalogue is checked before the roles are written. A role template can
 * only name permissions from config/permissions.php, and the permissions table
 * is a projection of that config -- so if a deploy adds a permission and nobody
 * re-runs the seeder, EVERY subsequent tenant creation dies on a raw spatie
 * PermissionDoesNotExist, with a stack trace instead of anything actionable.
 *
 * That is a deploy-ordering footgun rather than a one-off: it recurs every time
 * a permission is added. Syncing here removes it. The sync is documented as
 * idempotent and safe to run on every deploy, and it only runs at all when
 * something is genuinely absent.
 */
final class CreateTenantRolesService
{
    public function __construct(
        private readonly PermissionCatalogue $catalogue,
        private readonly SyncPermissionCatalogueService $sync,
    ) {}

    /**
     * @return Collection<string, Role> keyed by role name
     */
    public function execute(Tenant $tenant): Collection
    {
        $registrar = app(PermissionRegistrar::class);
        $previousTeam = $registrar->getPermissionsTeamId();

        // Role lookups are team-scoped; bind to this tenant for the duration
        // so firstOrCreate does not match another tenant's identically named
        // role.
        $this->ensureCatalogue(
            $this->catalogue->webGuardPermissions(),
            $this->catalogue->tenantGuard(),
        );

        $registrar->setPermissionsTeamId($tenant->getKey());

        try {
            $roles = collect($this->catalogue->roleNames())
                ->mapWithKeys(function (string $name) use ($tenant): array {
                    $role = Role::query()->firstOrCreate([
                        'name' => $name,
                        'guard_name' => $this->catalogue->tenantGuard(),
                        'team_id' => $tenant->getKey(),
                    ]);

                    $role->syncPermissions($this->catalogue->resolveRoleTemplate($name));

                    return [$name => $role];
                });
        } finally {
            $registrar->setPermissionsTeamId($previousTeam);
            $registrar->forgetCachedPermissions();
        }

        return $roles;
    }

    /**
     * Portal roles live on the `customer` guard and are also team-scoped, so a
     * client's approval rights never span agencies.
     *
     * @return Collection<string, Role>
     */
    public function executeForPortal(Tenant $tenant): Collection
    {
        $registrar = app(PermissionRegistrar::class);
        $previousTeam = $registrar->getPermissionsTeamId();

        $this->ensureCatalogue(
            $this->catalogue->portalPermissions(),
            $this->catalogue->portalGuard(),
        );

        $registrar->setPermissionsTeamId($tenant->getKey());

        try {
            $roles = collect($this->catalogue->portalRoleNames())
                ->mapWithKeys(function (string $name) use ($tenant): array {
                    $role = Role::query()->firstOrCreate([
                        'name' => $name,
                        'guard_name' => $this->catalogue->portalGuard(),
                        'team_id' => $tenant->getKey(),
                    ]);

                    $role->syncPermissions($this->catalogue->resolvePortalRoleTemplate($name));

                    return [$name => $role];
                });
        } finally {
            $registrar->setPermissionsTeamId($previousTeam);
            $registrar->forgetCachedPermissions();
        }

        return $roles;
    }

    /**
     * Make sure every permission a role template can name actually exists.
     *
     * Reported as a warning, not swallowed. The catalogue being out of date
     * means a deploy skipped its seeder, and healing it silently would hide
     * that until the next thing to depend on ordering broke instead -- so the
     * tenant is created AND the gap is on the record.
     *
     * @param  list<string>  $needed
     */
    private function ensureCatalogue(array $needed, string $guard): void
    {
        $have = Permission::query()
            ->where('guard_name', $guard)
            ->pluck('name')
            ->all();

        $missing = array_values(array_diff($needed, $have));

        if ($missing === []) {
            return;
        }

        Log::warning('The permission catalogue was out of date and has been re-synced.', [
            'guard' => $guard,
            'missing' => $missing,
            'hint' => 'Run php artisan db:seed --class=PermissionSeeder on deploy.',
        ]);

        $this->sync->execute();
    }
}
