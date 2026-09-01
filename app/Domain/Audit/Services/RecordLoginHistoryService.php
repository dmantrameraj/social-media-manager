<?php

declare(strict_types=1);

namespace App\Domain\Audit\Services;

use App\Domain\Audit\Enums\LoginEvent;
use App\Domain\Audit\Models\LoginHistory;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Writes authentication events.
 *
 * Failed attempts are recorded WITHOUT the attempted password. Storing it --
 * even hashed, even briefly -- turns the security log into a credential
 * database, and users frequently type a valid password for a different account
 * into the wrong form.
 */
final class RecordLoginHistoryService
{
    public function __construct(private readonly Request $request) {}

    public function record(
        LoginEvent $event,
        ?Authenticatable $user = null,
        ?string $attemptedEmail = null,
    ): void {
        $agent = (string) $this->request->userAgent();

        // forceCreate: the model is fully guarded because nothing user-supplied
        // may ever reach it. Every value below is assembled here from the
        // request and the resolved principal, never from input.
        LoginHistory::query()->forceCreate([
            'tenant_id' => $this->resolveTenantId($user),
            'authenticatable_type' => $user !== null ? $user::class : null,
            'authenticatable_id' => $user?->getAuthIdentifier(),
            'attempted_email' => $attemptedEmail !== null
                ? Str::limit(Str::lower($attemptedEmail), 190, '')
                : null,
            'event' => $event->value,
            'ip' => $this->request->ip(),
            'user_agent' => Str::limit($agent, 500, ''),
            'device' => $this->guessDevice($agent),
            'platform' => $this->guessPlatform($agent),
            'browser' => $this->guessBrowser($agent),
            'session_id' => $this->request->hasSession()
                ? $this->request->session()->getId()
                : null,
            'created_at' => now(),
        ]);
    }

    /**
     * A failed attempt has no resolved tenant, and for an unknown email we do
     * not look one up -- probing which addresses exist is exactly what an
     * attacker wants, and a lookup here would make timing reveal it.
     */
    private function resolveTenantId(?Authenticatable $user): ?int
    {
        if ($user === null) {
            return null;
        }

        return method_exists($user, 'getAttribute')
            ? $user->getAttribute('tenant_id')
            : null;
    }

    private function guessDevice(string $agent): ?string
    {
        return match (true) {
            str_contains($agent, 'Mobile') => 'mobile',
            str_contains($agent, 'Tablet'), str_contains($agent, 'iPad') => 'tablet',
            $agent === '' => null,
            default => 'desktop',
        };
    }

    private function guessPlatform(string $agent): ?string
    {
        return match (true) {
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Mac OS') => 'macOS',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone'), str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => null,
        };
    }

    private function guessBrowser(string $agent): ?string
    {
        return match (true) {
            // Order matters: Edge and Chrome both contain "Chrome", and most
            // browsers still claim "Safari".
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') => 'Opera',
            str_contains($agent, 'Firefox') => 'Firefox',
            str_contains($agent, 'Chrome') => 'Chrome',
            str_contains($agent, 'Safari') => 'Safari',
            default => null,
        };
    }
}
