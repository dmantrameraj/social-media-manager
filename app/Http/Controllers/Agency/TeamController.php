<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agency;

use App\Domain\Billing\Entitlements\EntitlementResolver;
use App\Domain\Billing\Entitlements\Exceptions\EntitlementExceeded;
use App\Domain\Tenancy\Exceptions\TeamChangeRejected;
use App\Domain\Tenancy\Models\Invitation;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantUser;
use App\Domain\Tenancy\Services\InviteTeamMemberService;
use App\Domain\Tenancy\Services\ManageTeamMemberService;
use App\Support\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;

final class TeamController
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EntitlementResolver $entitlements,
    ) {}

    public function index(Request $request): View
    {
        $request->user()->can('team.view') || abort(403);

        $tenant = $this->context->get();

        return view('agency.team.index', [
            'title' => 'Team',
            'members' => TenantUser::query()
                ->where('tenant_id', $tenant->getKey())
                ->with('user')
                ->get(),

            // Shown because a team screen that hides what everyone can do is
            // not a team screen. Resolved here rather than in the template so
            // the permission team id is set once for the whole list.
            'memberRoles' => $this->rolesFor($tenant),
            'ownerId' => $tenant->owner_user_id,
            'currentUserId' => $request->user()->getKey(),
            'canManage' => $request->user()->can('team.remove'),
            'canManageRoles' => $request->user()->can('team.manage_roles'),
            'invitations' => Invitation::query()->pending()->get(),
            'roles' => array_keys((array) config('permissions.roles', [])),
            'canInvite' => $request->user()->can('team.invite'),
            'used' => $this->entitlements->currentUsage($tenant, 'team_members.max'),
            'limit' => $this->entitlements->value($tenant, 'team_members.max'),
        ]);
    }

    public function invite(Request $request, InviteTeamMemberService $service): RedirectResponse
    {
        $request->user()->can('team.invite') || abort(403);

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            // Restricted to the configured templates: a free-text role would
            // let the form request a role that does not exist.
            'role' => ['required', 'string', 'in:'.implode(',', array_keys((array) config('permissions.roles', [])))],
        ]);

        try {
            ['token' => $token] = $service->execute(
                $this->context->get(),
                $request->user(),
                $validated['email'],
                $validated['role'],
            );
        } catch (EntitlementExceeded|RuntimeException $e) {
            // A seat limit is worth an upgrade link; "already a member" is not.
            return back()->withInput()
                ->with('error', $e->getMessage())
                ->with('upgrade_prompt', $e instanceof EntitlementExceeded);
        }

        /*
         | The raw token is returned exactly once and is never stored. Until
         | invitation email is wired up, it is surfaced here so an admin can
         | copy the link -- rather than silently creating an invitation nobody
         | can act on.
         */
        return back()->with('status', 'Invitation created. Link: '.route('invitations.accept', ['token' => $token]));
    }

    public function suspend(
        Request $request,
        TenantUser $member,
        ManageTeamMemberService $service,
    ): RedirectResponse {
        $request->user()->can('team.remove') || abort(403);

        $tenant = $this->assertOwnMember($member);

        try {
            $service->suspend($tenant, $member, $request->user());
        } catch (TeamChangeRejected $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Access suspended. It stops at their next request.');
    }

    public function reinstate(
        Request $request,
        TenantUser $member,
        ManageTeamMemberService $service,
    ): RedirectResponse {
        $request->user()->can('team.remove') || abort(403);

        $tenant = $this->assertOwnMember($member);

        try {
            $service->reinstate($tenant, $member, $request->user());
        } catch (TeamChangeRejected|EntitlementExceeded $e) {
            // Reinstating consumes a seat, so this can fail on the plan limit
            // -- but it can equally fail because they never accepted, which no
            // upgrade fixes.
            return back()
                ->with('error', $e->getMessage())
                ->with('upgrade_prompt', $e instanceof EntitlementExceeded);
        }

        return back()->with('status', 'Access restored.');
    }

    public function updateRole(
        Request $request,
        TenantUser $member,
        ManageTeamMemberService $service,
    ): RedirectResponse {
        // A separate permission from team.remove: deciding what someone may do
        // is a different authority from deciding whether they are here at all.
        $request->user()->can('team.manage_roles') || abort(403);

        $tenant = $this->assertOwnMember($member);

        $validated = $request->validate([
            'role' => ['required', 'string', 'in:'.implode(',', array_keys((array) config('permissions.roles', [])))],
        ]);

        try {
            $service->changeRole($tenant, $member, $validated['role'], $request->user());
        } catch (TeamChangeRejected $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Role updated.');
    }

    public function revokeInvitation(
        Request $request,
        Invitation $invitation,
        ManageTeamMemberService $service,
    ): RedirectResponse {
        $request->user()->can('team.invite') || abort(403);

        $tenant = $this->context->get();

        // Invitation IS tenant-scoped by the global scope, so a foreign row
        // never binds. Checked anyway: this is the one place a wrong answer
        // hands somebody a working link into another workspace.
        abort_unless($invitation->tenant_id === $tenant->getKey(), 404);

        try {
            $service->revokeInvitation($tenant, $invitation, $request->user());
        } catch (TeamChangeRejected $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Invitation revoked.');
    }

    /**
     * TenantUser is the join that DEFINES tenant access, so it carries no
     * tenant scope -- scoping it by the active tenant would be circular. That
     * makes this check the only thing standing between a guessed id and
     * another agency's membership row.
     */
    private function assertOwnMember(TenantUser $member): Tenant
    {
        $tenant = $this->context->get();

        abort_unless($member->tenant_id === $tenant->getKey(), 404);

        return $tenant;
    }

    /**
     * Role name per membership row, resolved once for the whole list.
     *
     * @return array<int, string>
     */
    private function rolesFor(Tenant $tenant): array
    {
        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($tenant->getKey());

        try {
            return DB::table(config('permission.table_names.model_has_roles', 'model_has_roles'))
                ->join(
                    config('permission.table_names.roles', 'roles'),
                    config('permission.table_names.model_has_roles', 'model_has_roles').'.role_id',
                    '=',
                    config('permission.table_names.roles', 'roles').'.id',
                )
                ->where(config('permission.table_names.model_has_roles', 'model_has_roles').'.team_id', $tenant->getKey())
                ->pluck(config('permission.table_names.roles', 'roles').'.name', 'model_id')
                ->map(fn ($name): string => (string) $name)
                ->all();
        } finally {
            $registrar->setPermissionsTeamId($previous);
        }
    }
}
