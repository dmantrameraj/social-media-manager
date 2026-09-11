<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agency;

use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\Services\AccountSecurityService;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The devices signed in to this account, and how to sign them out.
 *
 * `sessions.guard` was added in the first migration for exactly this and stayed
 * null, so until now the only way to end a session somewhere else was to change
 * your password and hope every device was logged out by it.
 *
 * No permission gate and no `password.confirm`. These are the signed-in user's
 * OWN sessions -- identity is the authorisation, as with notifications. And
 * somebody reaching this screen may be reacting to a stolen laptop: putting a
 * password prompt in front of the one action that helps is friction at the
 * moment it costs most, on an action whose worst outcome is being signed out.
 */
final class SessionController extends Controller
{
    /** The agency guard. Every query here is scoped by it as well as by id. */
    private const GUARD = 'web';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AccountSecurityService $security,
    ) {}

    public function index(Request $request): View
    {
        return view('agency.sessions.index', [
            'title' => 'Signed-in devices',
            'sessions' => $this->security->sessions(
                $request->user(),
                self::GUARD,
                $request->session()->getId(),
            ),
            'activity' => $this->security->recentActivity($request->user()),
        ]);
    }

    /**
     * End one session.
     */
    public function destroy(Request $request, string $session): RedirectResponse
    {
        if ($session === $request->session()->getId()) {
            // Ending the current session here would log you out mid-action with
            // no explanation. Logging out is what the logout button is for.
            return back()->with('error', 'That is this device. Use log out instead.');
        }

        if (! $this->security->endSession($request->user(), self::GUARD, $session)) {
            return back()->with('error', 'That session has already ended.');
        }

        $this->audit->log('auth.session_revoked', $request->user(), actor: $request->user());

        return back()->with('status', 'That device has been signed out.');
    }

    /**
     * End every session except this one.
     *
     * The action somebody actually wants when they think an account is
     * compromised: one click, everything else gone, without having to work out
     * which row is the laptop they left behind.
     */
    public function destroyOthers(Request $request): RedirectResponse
    {
        $count = $this->security->endOtherSessions(
            $request->user(),
            self::GUARD,
            $request->session()->getId(),
        );

        if ($count === 0) {
            return back()->with('status', 'No other devices were signed in.');
        }

        $this->audit->log(
            'auth.sessions_revoked',
            $request->user(),
            newValues: ['count' => $count],
            actor: $request->user(),
        );

        return back()->with('status', "Signed out {$count} other ".str('device')->plural($count).'.');
    }
}
