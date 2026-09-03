<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Billing\Entitlements\EntitlementResolver;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Enums\MembershipStatus;
use App\Domain\Tenancy\Models\Invitation;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantUser;
use App\Domain\Tenancy\Services\InviteTeamMemberService;
use App\Domain\Tenancy\Services\ProvisionTenantService;

/*
 | An agency could invite people and never remove them. MembershipStatus::
 | Suspended was defined and ResolveTenant already refused it, but nothing
 | could set it -- so somebody who left the agency kept full access to every
 | brand, post and media file, permanently.
 */

beforeEach(function (): void {
    seedPermissions();

    $this->owner = User::factory()->create();
    $this->tenant = app(ProvisionTenantService::class)->execute($this->owner, 'Bright Digital');
    $this->owner = $this->owner->fresh();

    actingForTenant($this->tenant);

    // The default plan allows a single member, which the owner already fills.
    // Reinstating and inviting both consume a seat, so without this the seat
    // guard -- not the behaviour under test -- decides every result.
    givePlanLimit($this->tenant->getKey(), 'team_members.max', 10);
    app(EntitlementResolver::class)->forget($this->tenant);
});

function asTeamAdmin(User $user)
{
    return test()->actingAs($user, 'web')->withSession([
        config('tenancy.resolution.session_key', 'tenant_id') => test()->tenant->getKey(),
    ]);
}

function membershipOf(Tenant $tenant, User $user): TenantUser
{
    return TenantUser::query()
        ->where('tenant_id', $tenant->getKey())
        ->where('user_id', $user->getKey())
        ->firstOrFail();
}

// ------------------------------------------------------------------ suspend

it('suspends a member', function (): void {
    $member = memberWithRole($this->tenant, 'Designer');

    asTeamAdmin($this->owner)
        ->post(route('agency.team.suspend', membershipOf($this->tenant, $member)))
        ->assertRedirect();

    expect(membershipOf($this->tenant, $member)->status)->toBe(MembershipStatus::Suspended);
});

it('locks a suspended member out on their very next request', function (): void {
    /*
     | The point of the whole feature. ResolveTenant re-reads the membership
     | with ->active() on every request and never trusts the session, so the
     | change lands immediately rather than whenever a session expires.
     */
    $member = memberWithRole($this->tenant, 'Manager');

    asTeamAdmin($this->owner)
        ->post(route('agency.team.suspend', membershipOf($this->tenant, $member)));

    // 403 from ResolveTenant, which also clears the tenant from the session:
    // a redirect would imply somewhere else to go, and there is not.
    asTeamAdmin($member)
        ->get(route('agency.dashboard'))
        ->assertForbidden();
});

it('frees the seat it was using', function (): void {
    // currentUsage counts active rows only, so a suspended member must stop
    // consuming a seat -- otherwise suspending somebody to hire their
    // replacement does not let you hire the replacement.
    $member = memberWithRole($this->tenant, 'Designer');

    $before = app(EntitlementResolver::class)
        ->currentUsage($this->tenant, 'team_members.max');

    asTeamAdmin($this->owner)
        ->post(route('agency.team.suspend', membershipOf($this->tenant, $member)));

    expect(app(EntitlementResolver::class)
        ->currentUsage($this->tenant, 'team_members.max'))->toBe($before - 1);
});

it('restores a suspended member', function (): void {
    $member = memberWithRole($this->tenant, 'Designer');
    $membership = membershipOf($this->tenant, $member);

    asTeamAdmin($this->owner)->post(route('agency.team.suspend', $membership));
    asTeamAdmin($this->owner)->post(route('agency.team.reinstate', $membership));

    expect(membershipOf($this->tenant, $member)->status)->toBe(MembershipStatus::Active);
});

// ------------------------------------------------------------------- guards

it('refuses to suspend yourself', function (): void {
    // Self-suspension locks you out on the next request, and the person who
    // could undo it is the person who just lost the ability to.
    asTeamAdmin($this->owner)
        ->post(route('agency.team.suspend', membershipOf($this->tenant, $this->owner)))
        ->assertSessionHas('error');

    expect(membershipOf($this->tenant, $this->owner)->status)->toBe(MembershipStatus::Active);
});

it('refuses to suspend the workspace owner', function (): void {
    // The owner is the billing and legal contact; losing them leaves a
    // subscription nobody can manage.
    $admin = memberWithRole($this->tenant, 'Agency Admin');

    asTeamAdmin($admin)
        ->post(route('agency.team.suspend', membershipOf($this->tenant, $this->owner)))
        ->assertSessionHas('error');

    expect(membershipOf($this->tenant, $this->owner)->status)->toBe(MembershipStatus::Active);
});

it('refuses a change that would leave nobody able to manage the workspace', function (): void {
    /*
     | The recovery trap: an agency with no administrator cannot add members,
     | cannot undo a mistaken suspension, and cannot get out of it without
     | somebody reaching into the database.
     */
    $admin = memberWithRole($this->tenant, 'Agency Admin');
    $tenant = $this->tenant;

    // Detach the owner so the admin is the only remaining administrator, then
    // have them demote themselves via another admin.
    TenantUser::query()
        ->where('tenant_id', $tenant->getKey())
        ->where('user_id', $this->owner->getKey())
        ->update(['status' => MembershipStatus::Suspended->value]);

    $tenant->owner_user_id = null;
    $tenant->save();

    $second = memberWithRole($tenant, 'Agency Admin');

    asTeamAdmin($second)
        ->post(route('agency.team.suspend', membershipOf($tenant, $admin)))
        ->assertRedirect();

    // One admin left; suspending them too must be refused.
    asTeamAdmin($admin)->post(route('agency.team.suspend', membershipOf($tenant, $second)));

    expect(membershipOf($tenant, $second)->status)->toBe(MembershipStatus::Active);
});

it('refuses to reinstate someone who never accepted their invitation', function (): void {
    // Flipping an invited row to active grants access to somebody who never
    // followed their invitation and never set a password.
    $invitee = User::factory()->create();

    $invitee->tenants()->attach($this->tenant->getKey(), [
        'status' => MembershipStatus::Invited->value,
        'invited_at' => now(),
    ]);

    asTeamAdmin($this->owner)
        ->post(route('agency.team.reinstate', membershipOf($this->tenant, $invitee)))
        ->assertSessionHas('error');

    expect(membershipOf($this->tenant, $invitee)->status)->toBe(MembershipStatus::Invited);
});

// --------------------------------------------------------------------- roles

it('changes a role', function (): void {
    $member = memberWithRole($this->tenant, 'Designer');

    asTeamAdmin($this->owner)
        ->put(route('agency.team.role', membershipOf($this->tenant, $member)), ['role' => 'Manager'])
        ->assertRedirect();

    setPermissionsTeamId($this->tenant->getKey());

    expect($member->fresh()->hasRole('Manager'))->toBeTrue();
});

it('replaces the old role rather than adding to it', function (): void {
    // assignRole would leave the old permissions in place -- a demotion that
    // granted everything it was meant to take away.
    $member = memberWithRole($this->tenant, 'Manager');

    asTeamAdmin($this->owner)
        ->put(route('agency.team.role', membershipOf($this->tenant, $member)), ['role' => 'Designer']);

    setPermissionsTeamId($this->tenant->getKey());

    $fresh = $member->fresh();

    expect($fresh->hasRole('Designer'))->toBeTrue()
        ->and($fresh->hasRole('Manager'))->toBeFalse()
        ->and($fresh->can('posts.create'))->toBeFalse();
});

it('refuses a role that does not exist', function (): void {
    $member = memberWithRole($this->tenant, 'Designer');

    asTeamAdmin($this->owner)
        ->put(route('agency.team.role', membershipOf($this->tenant, $member)), ['role' => 'Superuser'])
        ->assertSessionHasErrors('role');
});

// ------------------------------------------------------------- authorisation

it('refuses a member without the permission', function (): void {
    $designer = memberWithRole($this->tenant, 'Designer');
    $other = memberWithRole($this->tenant, 'Content Creator');

    asTeamAdmin($designer)
        ->post(route('agency.team.suspend', membershipOf($this->tenant, $other)))
        ->assertForbidden();
});

it('separates changing a role from removing access', function (): void {
    // Manager holds team.view but neither team.remove nor team.manage_roles:
    // deciding what someone may do is a different authority from deciding
    // whether they are here at all.
    $manager = memberWithRole($this->tenant, 'Manager');
    $other = memberWithRole($this->tenant, 'Designer');

    asTeamAdmin($manager)
        ->put(route('agency.team.role', membershipOf($this->tenant, $other)), ['role' => 'Manager'])
        ->assertForbidden();
});

it('answers 404 for another workspace is membership', function (): void {
    /*
     | TenantUser is the join that DEFINES tenant access, so it carries no
     | tenant scope -- the controller check is the only thing between a guessed
     | id and another agency's membership row.
     */
    $otherOwner = User::factory()->create();
    $otherTenant = app(ProvisionTenantService::class)->execute($otherOwner, 'Rival Agency');
    $foreign = membershipOf($otherTenant, $otherOwner->fresh());

    asTeamAdmin($this->owner)
        ->post(route('agency.team.suspend', $foreign))
        ->assertNotFound();

    expect($foreign->fresh()->status)->toBe(MembershipStatus::Active);
});

// --------------------------------------------------------------- invitations

it('revokes a pending invitation', function (): void {
    // revoked_at and scopePending both already existed; nothing set it, so an
    // invitation sent to the wrong address stayed usable until it expired.
    ['invitation' => $invitation] = app(InviteTeamMemberService::class)->execute(
        $this->tenant,
        $this->owner,
        'wrong@example.com',
        'Designer',
    );

    asTeamAdmin($this->owner)
        ->post(route('agency.team.invitation.revoke', $invitation))
        ->assertRedirect();

    expect($invitation->fresh()->revoked_at)->not->toBeNull()
        ->and(Invitation::query()->pending()->count())->toBe(0);
});

// --------------------------------------------------------------------- audit

it('audits every change', function (): void {
    $member = memberWithRole($this->tenant, 'Designer');

    asTeamAdmin($this->owner)
        ->post(route('agency.team.suspend', membershipOf($this->tenant, $member)));

    $entry = AuditLog::query()->where('action', 'tenancy.member_suspended')->latest('id')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->actor_id)->toBe($this->owner->getKey())
        ->and($entry->tenant_id)->toBe($this->tenant->getKey());
});
