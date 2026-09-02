<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Identity\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The page a user enrols in two-factor authentication from.
 *
 * Fortify supplies every endpoint (enable, confirm, QR code, secret, recovery
 * codes) but no screen to drive them, so `two-factor.enrol` did not exist --
 * and EnsureSuperAdmin redirected to it, meaning any Super Admin without 2FA
 * hit a RouteNotFoundException instead of being asked to enrol.
 *
 * Deliberately NOT behind the super-admin middleware. That middleware is what
 * sends people here, so gating this page on it would be a redirect loop; and
 * 2FA is worth offering to every user, not only to staff.
 */
final class TwoFactorEnrolmentController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        /*
         | Three states, because the middle one is where people get stuck: a
         | secret exists but has never been proved, so the account is not yet
         | protected and Fortify will not treat it as enrolled.
         */
        $state = match (true) {
            $user->two_factor_confirmed_at !== null => 'confirmed',
            $user->two_factor_secret !== null => 'pending',
            default => 'disabled',
        };

        return view('auth.two-factor-enrol', [
            'title' => 'Two-factor authentication',
            'user' => $user,
            'state' => $state,

            /*
             | Required because the admin surface refuses entry without 2FA.
             | Shown so the page can explain *why* the user was sent here,
             | rather than presenting an optional-looking security setting.
             */
            'required' => $user->isSuperAdmin(),

            // Rendered inline from Fortify's own accessors rather than fetched
            // over XHR, so the secret never travels as a separate cacheable
            // response.
            'qrCode' => $state === 'pending' ? $user->twoFactorQrCodeSvg() : null,
            'secret' => $state === 'pending' ? decrypt($user->two_factor_secret) : null,
            // Via the trait rather than decrypting by hand, so this keeps
            // working if Fortify changes how the codes are stored.
            'recoveryCodes' => $state === 'confirmed' ? $user->recoveryCodes() : null,
        ]);
    }
}
