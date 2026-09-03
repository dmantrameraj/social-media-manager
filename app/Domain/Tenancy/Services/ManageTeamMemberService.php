<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Services;

use App\Domain\Audit\AuditLogger;
use App\Domain\Billing\Entitlements\EntitlementResolver;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Enums\MembershipStatus;
use App\Domain\Tenancy\Exceptions\TeamChangeRejected;
use App\Domain\Tenancy\Models\Invitation;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantUser;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Suspends, reinstates and re-roles existing members.
 *
 * Until this existed an agency could invite people and never remove them.
 * MembershipStatus::Suspended was defined and permitsAccess() already refused
 * it, but nothing could set it -- so a person who left the agency kept full
 * access to every brand, post and media file for ever, and the mechanism to
 * stop them sat one unreachable step away.
 *
 * Suspension takes effect on the NEXT REQUEST rather than at some later sweep:
 * ResolveTenant re-reads the membership with ->active() every time and never
 * trusts the session, so the change lands the moment it is saved.
 */
final class ManageTeamMemberService
{
    /**
     * The permission that makes a workspace administrable.
     *
     * Whoever holds it can undo any change made here. An agency with nobody
     * holding it cannot add members, cannot fix a mistaken suspension, and
     * cannot recover without support reaching into the database.
     */
    private const ADMIN_PERMISSION = 'team.manage_roles';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly EntitlementResolver $entitlements,
    ) {}

    public function suspend(Tenant $tenant, TenantUser $member, User $actor): void
    {
        $this->assertNotSelf($member, $actor, 'suspend yourself');
        $this->assertNotOwner($tenant, $member, 'Suspend');

        if ($member->status === MembershipStatus::Suspended) {
            return;
        }

        DB::transaction(function () use ($tenant, $member, $actor): void {
            $member->status = MembershipStatus::Suspended;
            $member->save();

            $this->assertWorkspaceStillAdministrable($tenant);

            $this->audit->log(
                'tenancy.member_suspended',
                $member,
                oldValues: ['status' => MembershipStatus::Active->value],
                newValues: ['status' => MembershipStatus::Suspended->value],
                actor: $actor,
                tenantId: $tenant->getKey(),
            );
        });

        // A suspended member stops consuming a seat -- currentUsage counts
        // active rows only -- so the cached figure is wrong until forgotten.
        $this->entitlements->forget($tenant, 'team_members.max');
    }

    public function reinstate(Tenant $tenant, TenantUser $member, User $actor): void
    {
        if ($member->status === MembershipStatus::Active) {
            return;
        }

        /*
         | An invited member has not accepted yet. Flipping them to active here
         | would grant access to someone who never followed their invitation and
         | never set a password, which is a different and much worse thing than
         | reinstating somebody who once had access.
         */
        if ($member->status === MembershipStatus::Invited) {
            throw new TeamChangeRejected(
                'That person has not accepted their invitation yet.'
            );
        }

        // Reinstating consumes a seat again, so it is subject to the plan limit
        // exactly as an invitation is. Checked before the write.
        $this->entitlements->guard($tenant, 'team_members.max');

        $member->status = MembershipStatus::Active;
        $member->save();

        $this->entitlements->forget($tenant, 'team_members.max');

        $this->audit->log(
            'tenancy.member_reinstated',
            $member,
            oldValues: ['status' => MembershipStatus::Suspended->value],
            newValues: ['status' => MembershipStatus::Active->value],
            actor: $actor,
            tenantId: $tenant->getKey(),
        );
    }

    public function changeRole(Tenant $tenant, TenantUser $member, string $role, User $actor): void
    {
        $this->assertNotSelf($member, $actor, 'change your own role');
        $this->assertNotOwner($tenant, $member, 'Change the role of');

        // Never null: tenant_user.user_id cascades on delete, so a membership
        // row cannot outlive its user.
        $user = $member->user;

        $target = Role::query()
            ->where('name', $role)
            ->where('team_id', $tenant->getKey())
            ->first();

        if ($target === null) {
            throw new TeamChangeRejected("The role [{$role}] does not exist in this workspace.");
        }

        DB::transaction(function () use ($tenant, $member, $user, $target, $actor): void {
            $previous = $this->withTeam($tenant, fn (): array => $user->getRoleNames()->all());

            /*
             | syncRoles, not assignRole: a member holds exactly one role here,
             | and adding without removing would leave the old permissions in
             | place -- a "demotion" that granted everything it was meant to
             | take away.
             */
            $this->withTeam($tenant, function () use ($user, $target): void {
                $user->syncRoles([$target]);
            });

            $this->assertWorkspaceStillAdministrable($tenant);

            $this->audit->log(
                'tenancy.member_role_changed',
                $member,
                oldValues: ['roles' => $previous],
                newValues: ['roles' => [$target->name]],
                actor: $actor,
                tenantId: $tenant->getKey(),
            );
        });
    }

    /**
     * Withdraws an invitation that has not been accepted.
     *
     * revoked_at already existed and scopePending() already excluded it;
     * nothing ever set it, so an invitation sent to the wrong address stayed
     * usable until it expired on its own.
     */
    public function revokeInvitation(Tenant $tenant, Invitation $invitation, User $actor): void
    {
        if (! $invitation->isPending()) {
            throw new TeamChangeRejected(
                $invitation->unusableReason() ?? 'That invitation can no longer be revoked.'
            );
        }

        $invitation->revoked_at = now();
        $invitation->save();

        // The email is recorded because "who was invited and then un-invited"
        // is the question this entry exists to answer. The TOKEN is not, and is
        // hashed in the row regardless.
        $this->audit->log(
            'tenancy.invitation_revoked',
            $invitation,
            newValues: ['email' => $invitation->email],
            actor: $actor,
            tenantId: $tenant->getKey(),
        );
    }

    /**
     * @throws TeamChangeRejected
     */
    private function assertNotSelf(TenantUser $member, User $actor, string $action): void
    {
        /*
         | Self-suspension locks you out on the very next request, and a
         | self-demotion can remove the permission needed to undo itself. Either
         | way the person who could fix it is the person who just lost the
         | ability to.
         */
        if ($member->user_id === $actor->getKey()) {
            throw new TeamChangeRejected("You cannot {$action}.");
        }
    }

    /**
     * @throws TeamChangeRejected
     */
    private function assertNotOwner(Tenant $tenant, TenantUser $member, string $verb): void
    {
        // The owner is the billing and legal contact for the workspace. Losing
        // them leaves a subscription nobody can manage.
        if ($tenant->owner_user_id !== null && $member->user_id === $tenant->owner_user_id) {
            throw new TeamChangeRejected("{$verb} the workspace owner is not possible.");
        }
    }

    /**
     * At least one active member must still be able to administer the team.
     *
     * Asserted AFTER the change inside the transaction rather than predicted
     * before it: working out what a role template grants means expanding
     * wildcards and `except` lists, and a second implementation of that would
     * eventually disagree with the real one. Making the change and asking the
     * permission system directly cannot drift.
     *
     * @throws TeamChangeRejected
     */
    private function assertWorkspaceStillAdministrable(Tenant $tenant): void
    {
        $members = TenantUser::query()
            ->active()
            ->where('tenant_id', $tenant->getKey())
            ->with('user')
            ->get();

        $administrable = $this->withTeam($tenant, function () use ($members): bool {
            foreach ($members as $candidate) {
                // fresh() because syncRoles() ran moments ago on a different
                // instance of this same user; the loaded relation still holds
                // the roles it had before the change.
                if ($candidate->user->fresh()?->hasPermissionTo(self::ADMIN_PERMISSION)) {
                    return true;
                }
            }

            return false;
        });

        if (! $administrable) {
            throw new TeamChangeRejected(
                'That would leave nobody able to manage this workspace. '
                .'Give someone else an administrator role first.'
            );
        }
    }

    /**
     * Run a callback with the permission team set to this tenant.
     *
     * spatie/laravel-permission resolves roles against a globally set team id.
     * Restoring the previous value matters: this runs mid-request, and leaving
     * it pointed at another tenant would silently mis-resolve every later
     * permission check in the same process.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withTeam(Tenant $tenant, callable $callback): mixed
    {
        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();

        $registrar->setPermissionsTeamId($tenant->getKey());

        try {
            return $callback();
        } finally {
            $registrar->setPermissionsTeamId($previous);
            $registrar->forgetCachedPermissions();
        }
    }
}
