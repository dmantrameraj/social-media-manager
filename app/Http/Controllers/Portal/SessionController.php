<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Domain\Audit\Enums\LoginEvent;
use App\Domain\Audit\Services\RecordLoginHistoryService;
use App\Domain\Identity\Models\CustomerPortalUser;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Portal sign-in.
 *
 * Fortify drives the `web` guard only, and this is deliberately not wired into
 * it: sharing the login controller would mean one bug in a shared branch could
 * hand a client an agency session. The two surfaces have no shared controller
 * at all, per docs/04-AUTH-RBAC.md §8.
 */
final class SessionController extends Controller
{
    public function __construct(private readonly RecordLoginHistoryService $history) {}

    public function create(): View
    {
        return view('portal.auth.login', ['title' => 'Client sign in']);
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $this->assertNotRateLimited($request, $credentials['email']);

        $user = CustomerPortalUser::query()
            ->withoutGlobalScopes()
            ->where('email', Str::lower($credentials['email']))
            ->first();

        /*
         | withoutGlobalScopes because there is no tenant context yet: the
         | portal has no subdomain and no workspace picker, so the sign-in
         | itself is what establishes which tenant this person belongs to.
         | Every read after this point is scoped by brand assignment.
         */
        if ($user === null
            || ! $user->canAuthenticate()
            || ! password_verify($credentials['password'], $user->password)) {
            RateLimiter::hit($this->throttleKey($request, $credentials['email']));

            $this->history->record(
                event: LoginEvent::Failed,
                user: $user,
                attemptedEmail: $credentials['email'],
            );

            /*
             | One message for every failure -- unknown address, wrong password,
             | suspended account. Distinguishing them tells an attacker which
             | client emails exist on the platform.
             */
            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        RateLimiter::clear($this->throttleKey($request, $credentials['email']));

        $request->session()->regenerate();
        Auth::guard('customer')->login($user, $request->boolean('remember'));

        $this->history->record(event: LoginEvent::Login, user: $user);

        return redirect()->intended(route('portal.dashboard', absolute: false));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $this->history->record(event: LoginEvent::Logout, user: $request->user('customer'));

        Auth::guard('customer')->logout();

        // Invalidate rather than forget: a client signing out on a shared
        // machine must not leave a resumable session behind.
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('portal.login');
    }

    private function assertNotRateLimited(Request $request, string $email): void
    {
        $key = $this->throttleKey($request, $email);

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Too many attempts. Try again in '
                    .ceil(RateLimiter::availableIn($key) / 60).' minute(s).',
            ]);
        }
    }

    /**
     * Keyed on email AND ip together: ip alone lets one client office lock out
     * its own staff, email alone lets an attacker lock a known client out from
     * anywhere.
     */
    private function throttleKey(Request $request, string $email): string
    {
        return 'portal-login|'.Str::transliterate(Str::lower($email).'|'.$request->ip());
    }
}
