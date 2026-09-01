<?php

declare(strict_types=1);

namespace App\Domain\Access;

use InvalidArgumentException;

/**
 * Reads config/permissions.php and answers questions about it.
 *
 * Code owns the catalogue; the database is a projection of it. That way adding
 * a permission is a reviewable code change rather than a manual row insert
 * someone forgets to replicate to production.
 */
final class PermissionCatalogue
{
    /**
     * Every tenant-level permission, flattened out of its display groups.
     *
     * @return list<string>
     */
    public function tenantPermissions(): array
    {
        $grouped = (array) config('permissions.tenant', []);

        return array_values(array_unique(array_merge(...array_values(
            array_map(static fn (mixed $group): array => (array) $group, $grouped)
        ))));
    }

    /** @return list<string> */
    public function platformPermissions(): array
    {
        return array_values((array) config('permissions.platform', []));
    }

    /** @return list<string> */
    public function portalPermissions(): array
    {
        return array_values((array) config('permissions.portal', []));
    }

    /**
     * Everything on the `web` guard: tenant permissions plus platform ones.
     *
     * @return list<string>
     */
    public function webGuardPermissions(): array
    {
        return array_values(array_unique(array_merge(
            $this->tenantPermissions(),
            $this->platformPermissions(),
        )));
    }

    /** Display groups, for the role editor UI. @return array<string, list<string>> */
    public function groups(): array
    {
        return array_map(
            static fn (mixed $group): array => array_values((array) $group),
            (array) config('permissions.tenant', []),
        );
    }

    public function tenantGuard(): string
    {
        return (string) config('permissions.guards.tenant', 'web');
    }

    public function portalGuard(): string
    {
        return (string) config('permissions.guards.portal', 'customer');
    }

    /**
     * Resolve a role template into a concrete permission list.
     *
     * Templates may be:
     *   ['*']                        every tenant permission
     *   ['except' => [...]]          every tenant permission minus these
     *   ['posts.view', ...]          an explicit list
     *
     * @return list<string>
     */
    public function resolveRoleTemplate(string $role): array
    {
        $template = config("permissions.roles.{$role}");

        if ($template === null) {
            throw new InvalidArgumentException("Unknown role template [{$role}].");
        }

        $all = $this->tenantPermissions();

        if ($template === ['*']) {
            return $all;
        }

        if (is_array($template) && array_key_exists('except', $template)) {
            $except = (array) $template['except'];

            return array_values(array_diff($all, $except));
        }

        $explicit = array_values((array) $template);

        // Fail loudly on a typo. A permission that does not exist in the
        // catalogue would silently grant nothing, producing a role that looks
        // configured but denies everything.
        $unknown = array_diff($explicit, $all);

        if ($unknown !== []) {
            throw new InvalidArgumentException(
                "Role [{$role}] references unknown permissions: ".implode(', ', $unknown)
            );
        }

        return $explicit;
    }

    /** @return list<string> */
    public function roleNames(): array
    {
        return array_keys((array) config('permissions.roles', []));
    }

    /** @return list<string> */
    public function portalRoleNames(): array
    {
        return array_keys((array) config('permissions.portal_roles', []));
    }

    /** @return list<string> */
    public function resolvePortalRoleTemplate(string $role): array
    {
        $template = config("permissions.portal_roles.{$role}");

        if ($template === null) {
            throw new InvalidArgumentException("Unknown portal role template [{$role}].");
        }

        $explicit = array_values((array) $template);
        $unknown = array_diff($explicit, $this->portalPermissions());

        if ($unknown !== []) {
            throw new InvalidArgumentException(
                "Portal role [{$role}] references unknown permissions: ".implode(', ', $unknown)
            );
        }

        return $explicit;
    }

    /**
     * Platform permissions must never be attachable to a tenant role -- that
     * would let an agency owner grant themselves cross-tenant access.
     */
    public function isPlatformPermission(string $permission): bool
    {
        return in_array($permission, $this->platformPermissions(), true);
    }
}
