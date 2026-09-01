<?php

declare(strict_types=1);

namespace App\Domain\Access\Services;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds a tenant's role templates.
 *
 * Roles carry team_id = tenant_id, so two agencies each own an independent
 * "Manager" role and one editing theirs cannot affect the other.
 */
final class CreateTenantRolesService
{
    public function __construct(private readonly PermissionCatalogue $catalogue) {}

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
}
