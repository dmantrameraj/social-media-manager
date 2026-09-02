<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Domain\Identity\Models\User;
use App\Support\HomeRedirector;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse;
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

        $this->registerHomeRedirects();
        $this->registerViews();
        $this->registerAuthentication();
        $this->registerRateLimiters();
    }

    /**
     * Send each principal to the surface they can actually use.
     *
     * Fortify's `home` config is one static path, so platform staff -- who
     * usually belong to no agency -- were sent to /app and met a 403 from
     * ResolveTenant. Login worked and landed nowhere.
     *
     * Both responses are bound, because a user with two-factor enabled never
     * passes through LoginResponse at all: the challenge completes through
     * TwoFactorLoginResponse instead. Binding only the first would fix the
     * redirect for exactly the accounts that do not have 2FA, and leave it
     * broken for every Super Admin, who are required to have it.
     */
    private function registerHomeRedirects(): void
    {
        $redirect = fn (Request $request) => redirect()->intended(
            $this->app->make(HomeRedirector::class)->pathFor($request->user()),
        );

        $this->app->instance(LoginResponse::class, new class($redirect) implements LoginResponse
        {
            public function __construct(private $redirect) {}

            public function toResponse($request)
            {
                return ($this->redirect)($request);
            }
        });

        $this->app->instance(TwoFactorLoginResponse::class, new class($redirect) implements TwoFactorLoginResponse
        {
            public function __construct(private $redirect) {}

            public function toResponse($request)
            {
                return ($this->redirect)($request);
            }
        });
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
