<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Services;

use App\Domain\Access\Services\CreateTenantRolesService;
use App\Domain\AI\Models\AiCreditAccount;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Enums\MembershipStatus;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Enums\TenantType;
use App\Domain\Tenancy\Exceptions\MissingOwnerRoleException;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates a tenant and everything it cannot function without.
 *
 * Both entry points use this: self-serve signup and Super Admin manual
 * activation. Keeping them on one path is what stops the sales flow drifting
 * into a second, half-maintained implementation.
 *
 * See docs/04-AUTH-RBAC.md §2.
 */
final class ProvisionTenantService
{
    /** The role the creating user receives. Must exist in the catalogue. */
    private const OWNER_ROLE = 'Agency Owner';

    public function __construct(
        private readonly CreateTenantRolesService $roles,
    ) {}

    public function execute(
        User $owner,
        string $name,
        ?string $slug = null,
        ?string $timezone = null,
        TenantStatus $status = TenantStatus::Trialing,
        TenantType $type = TenantType::Agency,
    ): Tenant {
        return DB::transaction(function () use ($owner, $name, $slug, $timezone, $status, $type): Tenant {
            $tenant = new Tenant;
            $tenant->name = $name;
            $tenant->slug = $this->uniqueSlug($slug ?? $name);
            $tenant->type = $type;
            $tenant->status = $status;
            $tenant->timezone = $timezone ?? (string) config('app.timezone', 'UTC');
            $tenant->owner_user_id = $owner->getKey();

            if ($status === TenantStatus::Trialing) {
                $tenant->trial_ends_at = now()->addDays((int) config('tenancy.trial_days', 7));
            }

            $tenant->save();

            $roles = $this->roles->execute($tenant);
            $this->roles->executeForPortal($tenant);

            // Fail loudly rather than provisioning a tenant whose owner holds
            // no permissions. assignRole(null) is silently ignored by spatie,
            // so without this check a missing template produces a tenant that
            // nobody -- including its owner -- can administer.
            $ownerRole = $roles->get(self::OWNER_ROLE);

            if ($ownerRole === null) {
                throw new MissingOwnerRoleException(self::OWNER_ROLE);
            }

            $owner->tenants()->attach($tenant->getKey(), [
                'status' => MembershipStatus::Active->value,
                'joined_at' => now(),
            ]);

            // Role assignment is team-scoped: bind to the new tenant so the
            // owner gets THIS tenant's Agency Owner role, not another's.
            $registrar = app(PermissionRegistrar::class);
            $previousTeam = $registrar->getPermissionsTeamId();
            $registrar->setPermissionsTeamId($tenant->getKey());

            try {
                $owner->assignRole($ownerRole);
            } finally {
                $registrar->setPermissionsTeamId($previousTeam);
            }

            $this->openCreditAccount($tenant);

            return $tenant;
        });
    }

    /**
     * Every tenant gets a credit account at creation, even on a plan with a
     * zero allowance. Creating it lazily on first AI use would mean the
     * ledger's history starts partway through the tenant's life.
     */
    private function openCreditAccount(Tenant $tenant): AiCreditAccount
    {
        $account = new AiCreditAccount;
        $account->tenant_id = $tenant->getKey();
        $account->balance = 0;
        $account->reserved = 0;
        $account->monthly_allowance = 0;
        $account->period_start = now();
        $account->period_end = now()->addMonthNoOverflow();
        $account->rollover_enabled = false;
        $account->save();

        return $account;
    }

    /**
     * Slugs are globally unique and become part of a URL, so collisions are
     * resolved here rather than surfaced as a constraint violation.
     */
    private function uniqueSlug(string $source): string
    {
        $base = Str::slug($source) ?: 'agency';
        $base = Str::limit($base, 60, '');

        $reserved = (array) config('tenancy.reserved_slugs', []);
        $candidate = in_array($base, $reserved, true) ? $base.'-agency' : $base;

        $attempt = 0;

        while ($this->slugTaken($candidate)) {
            $attempt++;
            $candidate = $base.'-'.Str::lower(Str::random(6));

            if ($attempt > 10) {
                $candidate = $base.'-'.Str::lower(Str::ulid()->toString());
                break;
            }
        }

        return $candidate;
    }

    private function slugTaken(string $slug): bool
    {
        // withTrashed: a soft-deleted tenant still holds its slug, and the
        // unique index does not care that the row is logically deleted.
        return Tenant::query()->withTrashed()->where('slug', $slug)->exists();
    }
}
