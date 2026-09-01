<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Services;

use App\Domain\Billing\Entitlements\EntitlementResolver;
use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Enums\MembershipStatus;
use App\Domain\Tenancy\Exceptions\InvitationUnusable;
use App\Domain\Tenancy\Models\Invitation;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Consumes an invitation and joins the user to the workspace.
 *
 * The invitation is looked up by the HASH of the presented token, so the raw
 * value never needs to exist in the database to be verifiable.
 */
final class AcceptInvitationService
{
    public function __construct(private readonly EntitlementResolver $entitlements) {}

    public function execute(string $token, User $user): Invitation
    {
        $invitation = Invitation::query()
            ->acrossTenants()
            ->where('token_hash', hash('sha256', $token))
            ->first();

        if ($invitation === null) {
            throw new InvitationUnusable('This invitation link is not valid.');
        }

        if (($reason = $invitation->unusableReason()) !== null) {
            throw new InvitationUnusable($reason);
        }

        // The invitation is bound to an address. Letting a different account
        // consume it would turn a forwarded email into unauthorised access.
        if (! hash_equals($invitation->email, mb_strtolower($user->email))) {
            throw new InvitationUnusable('This invitation was issued to a different email address.');
        }

        $tenant = $invitation->tenant;

        // Re-checked at acceptance, not only at send: seats may have been
        // filled, or the plan downgraded, since the invitation went out.
        $this->entitlements->guard($tenant, 'team_members.max');

        return DB::transaction(function () use ($invitation, $user, $tenant): Invitation {
            // Atomic single-use claim. Two simultaneous accepts of the same
            // link must not both succeed.
            $claimed = Invitation::query()
                ->acrossTenants()
                ->whereKey($invitation->getKey())
                ->whereNull('accepted_at')
                ->update(['accepted_at' => now()]);

            if ($claimed !== 1) {
                throw new InvitationUnusable('This invitation has already been accepted.');
            }

            $user->tenants()->syncWithoutDetaching([
                $tenant->getKey() => [
                    'status' => MembershipStatus::Active->value,
                    'joined_at' => now(),
                ],
            ]);

            $this->assignRole($invitation, $user, $tenant->getKey());
            $this->assignBrands($invitation, $user, $tenant->getKey());

            $this->entitlements->forget($tenant, 'team_members.max');

            return $invitation->refresh();
        });
    }

    private function assignRole(Invitation $invitation, User $user, int $tenantId): void
    {
        if ($invitation->role_id === null) {
            return;
        }

        $role = Role::query()->find($invitation->role_id);

        if ($role === null) {
            return;
        }

        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($tenantId);

        try {
            $user->assignRole($role);
        } finally {
            $registrar->setPermissionsTeamId($previous);
        }
    }

    private function assignBrands(Invitation $invitation, User $user, int $tenantId): void
    {
        $ids = $invitation->customer_ids ?? [];

        if ($ids === []) {
            return;
        }

        // Re-validated at acceptance: a brand may have been deleted, or moved,
        // between issuing and accepting.
        Customer::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $ids)
            ->get()
            ->each(fn (Customer $customer) => $customer->users()->syncWithoutDetaching([$user->getKey()]));

        $user->forgetAssignedCustomers();
    }
}
