<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\Services\AccountSecurityService;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The devices signed in to a client's portal account.
 *
 * The agency side has had this since Phase 1 and the portal has not, which is
 * the wrong way round to leave it: a client's account is the one an agency
 * does not control. If a brand approver's password leaks, everything that
 * account can see — unpublished campaigns, approval conversations, a month of
 * planned content — is exposed, and until now the client had no way to notice
 * or to end the session.
 *
 * `login_histories` has recorded their sign-ins all along, on the same morph
 * relation the agency screen reads. Only the screen was missing.
 *
 * No permission gate. These are the signed-in client's OWN sessions and their
 * own history: identity is the authorisation, exactly as on the agency side.
 * And somebody reaching this screen may be reacting to a stolen laptop —
 * putting a password prompt in front of the one action that helps is friction
 * at the moment it costs most, on an action whose worst outcome is being
 * signed out.
 */
final class DeviceController extends Controller
{
    /** The portal guard. Every query here is scoped by it as well as by id. */
    private const GUARD = 'customer';

    public function __construct(
        private readonly AccountSecurityService $security,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user(self::GUARD);

        return view('portal.devices.index', [
            'title' => 'Security',
            'sessions' => $this->security->sessions($user, self::GUARD, $request->session()->getId()),
            'activity' => $this->security->recentActivity($user),
        ]);
    }

    public function destroy(Request $request, string $session): RedirectResponse
    {
        if ($session === $request->session()->getId()) {
            // Ending the current session from here would sign somebody out
            // mid-action with no explanation. Signing out is what sign out is
            // for.
            return back()->with('error', 'That is this device. Use sign out instead.');
        }

        $user = $request->user(self::GUARD);

        if (! $this->security->endSession($user, self::GUARD, $session)) {
            return back()->with('error', 'That session has already ended.');
        }

        $this->audit->log('auth.session_revoked', $user, actor: $user);

        return back()->with('status', 'That device has been signed out.');
    }

    public function destroyOthers(Request $request): RedirectResponse
    {
        $user = $request->user(self::GUARD);

        $count = $this->security->endOtherSessions(
            $user,
            self::GUARD,
            $request->session()->getId(),
        );

        if ($count === 0) {
            return back()->with('status', 'No other devices were signed in.');
        }

        $this->audit->log(
            'auth.sessions_revoked',
            $user,
            newValues: ['count' => $count],
            actor: $user,
        );

        return back()->with('status', "Signed out {$count} other ".str('device')->plural($count).'.');
    }
}
