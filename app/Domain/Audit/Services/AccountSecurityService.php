<?php

declare(strict_types=1);

namespace App\Domain\Audit\Services;

use App\Domain\Audit\Models\LoginHistory;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Where is my account signed in, and what has happened to it?"
 *
 * Shared by the agency and the portal because it is the same question asked by
 * two kinds of person. A client whose portal account is taken over loses their
 * unpublished campaign; a staff member who loses theirs loses every client's.
 * Both need the same answer, and writing it twice would mean the second copy
 * drifts — which for a security screen means one surface quietly stops
 * showing something.
 *
 * A domain service rather than a shared Blade component: 01-ARCHITECTURE.md §5
 * forbids a component namespace spanning surfaces so a mis-scoped include
 * cannot carry a screen between them. That rule is about views. The two
 * screens are written separately and read from here.
 *
 * THE GUARD IS ALWAYS EXPLICIT. Ids overlap between `users` and
 * `customer_portal_users`, so every query below is scoped by guard as well as
 * id — without it, a staff member and a client with the same id would see each
 * other's devices.
 */
final class AccountSecurityService
{
    /**
     * Every browser currently signed in to this account.
     *
     * A plain list rather than a Collection: this is a read model the view
     * iterates once, and Collection's value template is invariant, so a mapped
     * collection cannot satisfy a declared shape even when the shape matches.
     *
     * @return list<array{id: string, is_current: bool, ip_address: ?string, device: string, last_active: int}>
     */
    public function sessions(Authenticatable $user, string $guard, string $currentId): array
    {
        return DB::table('sessions')
            ->where('user_id', $user->getAuthIdentifier())
            ->where('guard', $guard)
            ->orderByDesc('last_activity')
            ->get()
            ->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'is_current' => (string) $row->id === $currentId,
                'ip_address' => $row->ip_address === null ? null : (string) $row->ip_address,
                'device' => $this->describe((string) ($row->user_agent ?? '')),
                'last_active' => (int) $row->last_activity,
            ])
            ->values()
            ->all();
    }

    /**
     * This account's recent authentication events.
     *
     * Scoped by the morph TYPE as well as the id, which is what the guard
     * column does for sessions: without it a staff member would be shown a
     * client's sign-in history whenever the two share an id.
     *
     * Bounded, because an account with years of history behind it should not
     * make this screen slow at the moment it is wanted.
     *
     * @return Collection<int, LoginHistory>
     */
    public function recentActivity(Authenticatable $user): Collection
    {
        return LoginHistory::query()
            ->where('authenticatable_type', $user::class)
            ->where('authenticatable_id', $user->getAuthIdentifier())
            ->latest('created_at')
            ->latest('id')
            ->limit((int) config('audit.login_history_shown', 20))
            ->get();
    }

    /**
     * End one session.
     *
     * Scoped to the caller's own id AND guard, so a session id guessed or
     * copied from elsewhere matches nothing. The id arrives from a form, which
     * is exactly the input that must never identify a row on its own.
     */
    public function endSession(Authenticatable $user, string $guard, string $sessionId): bool
    {
        return DB::table('sessions')
            ->where('id', $sessionId)
            ->where('user_id', $user->getAuthIdentifier())
            ->where('guard', $guard)
            ->delete() > 0;
    }

    /**
     * End every session except the one asking.
     *
     * The action somebody actually wants when they think an account is
     * compromised: one click, everything else gone, without having to work out
     * which row is the laptop they left on a train.
     */
    public function endOtherSessions(Authenticatable $user, string $guard, string $currentId): int
    {
        return DB::table('sessions')
            ->where('user_id', $user->getAuthIdentifier())
            ->where('guard', $guard)
            ->where('id', '!=', $currentId)
            ->delete();
    }

    /**
     * A readable device name from a user agent string.
     *
     * Deliberately coarse. The question is "is one of these not me?", which
     * needs only enough to recognise your own devices — and a full UA parser
     * is a dependency plus a fingerprinting surface for one line of text.
     */
    public function describe(string $userAgent): string
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
