<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Domain\Audit\Enums\LoginEvent;
use App\Domain\Audit\Services\RecordLoginHistoryService;
use App\Domain\Identity\Models\CustomerPortalUser;
use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/**
 * Client password reset.
 *
 * Its own controller on the `customers` broker, sharing nothing with Fortify's
 * agency flow. Before this existed, a client who forgot their password had to
 * ask the agency to re-invite them -- which meant the agency handling a
 * credential problem for someone whose account they cannot see into.
 */
final class PasswordResetController extends Controller
{
    public function __construct(private readonly RecordLoginHistoryService $history) {}

    public function request(): View
    {
        return view('portal.auth.forgot-password', ['title' => 'Reset your password']);
    }

    public function email(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $status = Password::broker('customers')->sendResetLink([
            'email' => Str::lower($validated['email']),
        ]);

        /*
         | The same answer whichever way it went.
         |
         | "No account with that address" turns this form into a way to ask
         | whether a given person is a client of this agency. The broker's own
         | throttling still applies, and its INVALID_USER result is folded into
         | the success message rather than surfaced.
         */
        return back()->with('status', $status === Password::RESET_THROTTLED
            ? 'A link was sent recently. Check your inbox, then try again in a few minutes.'
            : 'If that address has an account, a reset link is on its way.');
    }

    public function reset(Request $request, string $token): View
    {
        return view('portal.auth.reset-password', [
            'title' => 'Choose a new password',
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            // Laravel's default rules plus a check against known breaches. A
            // client account can approve content that goes out under the
            // agency's name, so it is not a low-value credential.
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::broker('customers')->reset(
            [
                'email' => Str::lower($validated['email']),
                'password' => $validated['password'],
                'password_confirmation' => $request->input('password_confirmation'),
                'token' => $validated['token'],
            ],
            function (CustomerPortalUser $user, string $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    // Rotated so a session held elsewhere with a remember
                    // cookie cannot survive a reset -- the usual reason someone
                    // resets is that they think someone else has access.
                    'remember_token' => Str::random(60),
                ])->save();

                $this->history->record(event: LoginEvent::PasswordReset, user: $user);

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => 'That reset link is no longer valid. Ask for a new one.',
            ]);
        }

        return redirect()
            ->route('portal.login')
            ->with('status', 'Your password has been reset. You can sign in now.');
    }
}
