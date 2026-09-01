<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Domain\Identity\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);

        $this->registerViews();
        $this->registerAuthentication();
        $this->registerRateLimiters();
    }

    private function registerViews(): void
    {
        Fortify::loginView(fn () => view('auth.login'));
        Fortify::registerView(fn () => view('auth.register'));
        Fortify::requestPasswordResetLinkView(fn () => view('auth.forgot-password'));
        Fortify::resetPasswordView(fn (Request $request) => view('auth.reset-password', ['request' => $request]));
        Fortify::verifyEmailView(fn () => view('auth.verify-email'));
        Fortify::confirmPasswordView(fn () => view('auth.confirm-password'));
        Fortify::twoFactorChallengeView(fn () => view('auth.two-factor-challenge'));
    }

    /**
     * A disabled account must not authenticate even with correct credentials.
     *
     * Fortify's default pipeline only checks the password, so the status check
     * lives here. It returns null rather than a distinct error, so a disabled
     * account is indistinguishable from a wrong password to an attacker.
     */
    private function registerAuthentication(): void
    {
        Fortify::authenticateUsing(function (Request $request): ?User {
            $user = User::query()
                ->where('email', (string) $request->input(Fortify::username()))
                ->first();

            if ($user === null || ! $user->canAuthenticate()) {
                return null;
            }

            return password_verify((string) $request->input('password'), $user->password)
                ? $user
                : null;
        });
    }

    private function registerRateLimiters(): void
    {
        /*
         | Keyed on email AND ip together: keying on ip alone lets one office
         | lock out its own staff, and keying on email alone lets an attacker
         | lock a known account out from anywhere.
         */
        RateLimiter::for('login', function (Request $request) {
            $key = Str::transliterate(
                Str::lower((string) $request->input(Fortify::username())).'|'.$request->ip()
            );

            return Limit::perMinute(5)->by($key);
        });

        // Keyed on the pending login id, not the ip: the challenge is already
        // bound to a half-authenticated session.
        RateLimiter::for('two-factor', fn (Request $request) => Limit::perMinute(5)
            ->by((string) $request->session()->get('login.id')));

        RateLimiter::for('password-reset', fn (Request $request) => Limit::perHour(3)
            ->by(Str::lower((string) $request->input('email')).'|'.$request->ip()));
    }
}
