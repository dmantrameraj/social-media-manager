<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Services;

use App\Domain\Billing\Entitlements\EntitlementResolver;
use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Invitation;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Issues a team invitation.
 *
 * Returns the raw token exactly once, to the caller, for embedding in the
 * email. It is never persisted and cannot be recovered afterwards -- a lost
 * invitation is re-issued, not looked up.
 */
final class InviteTeamMemberService
{
    public function __construct(private readonly EntitlementResolver $entitlements) {}

    /**
     * @param  list<int>  $customerIds  brands the invitee will be assigned to
     * @return array{invitation: Invitation, token: string}
     */
    public function execute(
        Tenant $tenant,
        User $actor,
        string $email,
        string $role,
        array $customerIds = [],
    ): array {
        $email = Str::lower(trim($email));

        // The seat is consumed on acceptance, but the limit is checked here:
        // sending five invitations against one remaining seat would otherwise
        // produce four confusing failures at accept time.
        $this->entitlements->guard($tenant, 'team_members.max');

        $this->assertNotAlreadyMember($tenant, $email);
        $this->assertBrandsBelongToTenant($tenant, $customerIds);

        // 32 random bytes, hex-encoded. Only the hash is stored.
        $token = bin2hex(random_bytes(32));

        $invitation = DB::transaction(function () use (
            $tenant, $actor, $email, $role, $customerIds, $token
        ): Invitation {
            // Supersede any outstanding invitation for this address so a
            // re-invite does not leave two usable tokens in circulation.
            Invitation::query()
                ->forTenant($tenant)
                ->where('email', $email)
                ->pending()
                ->update(['revoked_at' => now()]);

            $invitation = new Invitation;
            $invitation->tenant_id = $tenant->getKey();
            $invitation->email = $email;
            $invitation->role_id = $this->resolveRoleId($tenant, $role);
            $invitation->customer_ids = $customerIds;
            $invitation->token_hash = hash('sha256', $token);
            $invitation->expires_at = now()->addDays(
                (int) config('tenancy.invitation_expiry_days', 7)
            );
            $invitation->invited_by_user_id = $actor->getKey();
            $invitation->save();

            return $invitation;
        });

        return ['invitation' => $invitation, 'token' => $token];
    }

    public function revoke(Invitation $invitation): void
    {
        $invitation->revoked_at = now();
        $invitation->save();
    }

    private function assertNotAlreadyMember(Tenant $tenant, string $email): void
    {
        $alreadyMember = User::query()
            ->where('email', $email)
            ->whereHas('memberships', fn ($q) => $q
                ->where('tenant_id', $tenant->getKey()))
            ->exists();

        if ($alreadyMember) {
            throw new RuntimeException("{$email} is already a member of this workspace.");
        }
    }

    /**
     * A brand id arriving from a request must be proven to belong to this
     * tenant before it is written into the invitation -- otherwise accepting
     * would assign the new member to another agency's brand.
     *
     * @param  list<int>  $customerIds
     */
    private function assertBrandsBelongToTenant(Tenant $tenant, array $customerIds): void
    {
        if ($customerIds === []) {
            return;
        }

        $valid = Customer::query()
            ->forTenant($tenant)
            ->whereIn('id', $customerIds)
            ->count();

        if ($valid !== count(array_unique($customerIds))) {
            throw new RuntimeException('One or more brands do not belong to this workspace.');
        }
    }

    private function resolveRoleId(Tenant $tenant, string $role): int
    {
        $roleId = DB::table(config('permission.table_names.roles', 'roles'))
            ->where('team_id', $tenant->getKey())
            ->where('name', $role)
            ->where('guard_name', config('permissions.guards.tenant', 'web'))
            ->value('id');

        if ($roleId === null) {
            throw new RuntimeException("Role [{$role}] does not exist in this workspace.");
        }

        return (int) $roleId;
    }
}
