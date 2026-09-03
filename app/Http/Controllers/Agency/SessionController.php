<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agency;

use App\Domain\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $current = $request->session()->getId();

        $sessions = DB::table('sessions')
            ->where('user_id', $request->user()->getKey())
            // The guard matters: ids overlap between `users` and
            // `customer_portal_users`, so user_id alone would list a client's
            // devices alongside a staff member's.
            ->where('guard', 'web')
            ->orderByDesc('last_activity')
            ->get()
            ->map(fn (object $row): array => [
                'id' => $row->id,
                'is_current' => $row->id === $current,
                'ip_address' => $row->ip_address,
                'device' => $this->describe((string) ($row->user_agent ?? '')),
                'last_active' => $row->last_activity,
            ]);

        return view('agency.sessions.index', [
            'title' => 'Signed-in devices',
            'sessions' => $sessions,
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

        /*
         | Scoped to the caller's own id AND guard, so a session id guessed or
         | copied from elsewhere matches nothing. The id is the session's own
         | primary key and arrives from a form, which is exactly the input that
         | must never be trusted to identify a row on its own.
         */
        $deleted = DB::table('sessions')
            ->where('id', $session)
            ->where('user_id', $request->user()->getKey())
            ->where('guard', 'web')
            ->delete();

        if ($deleted === 0) {
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
        $count = DB::table('sessions')
            ->where('user_id', $request->user()->getKey())
            ->where('guard', 'web')
            ->where('id', '!=', $request->session()->getId())
            ->delete();

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

    /**
     * A readable device name from a user agent string.
     *
     * Deliberately coarse. The point is "is one of these not me?", which needs
     * only enough to recognise your own devices -- and a full UA parser is a
     * dependency plus a fingerprinting surface for a line of text.
     */
    private function describe(string $userAgent): string
    {
        if ($userAgent === '') {
            return 'Unknown device';
        }

        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'OPR/') => 'Opera',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Chrome/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => 'Browser',
        };

        $platform = match (true) {
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'iPad') => 'iOS',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'Mac OS') => 'macOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => 'Unknown platform',
        };

        return "{$browser} on {$platform}";
    }
}
