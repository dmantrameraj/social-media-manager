<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agency;

use App\Domain\Billing\Entitlements\EntitlementResolver;
use App\Domain\Billing\Entitlements\Exceptions\EntitlementExceeded;
use App\Domain\Tenancy\Models\Invitation;
use App\Domain\Tenancy\Models\TenantUser;
use App\Domain\Tenancy\Services\InviteTeamMemberService;
use App\Support\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

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
            return back()->withInput()->with('error', $e->getMessage());
        }

        /*
         | The raw token is returned exactly once and is never stored. Until
         | invitation email is wired up, it is surfaced here so an admin can
         | copy the link -- rather than silently creating an invitation nobody
         | can act on.
         */
        return back()->with('status', 'Invitation created. Link: '.route('invitations.accept', ['token' => $token]));
    }
}
