<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Identity\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the /admin surface.
 *
 * Three independent checks, all required:
 *   1. the principal is a User (not a portal user on some other guard)
 *   2. is_super_admin is set
 *   3. two-factor is confirmed
 *
 * 2FA is mandatory here and is enforced in middleware rather than policy,
 * because it must hold for every admin route including ones added later that
 * forget to declare a policy.
 */
final class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Type check first: a portal user must never be evaluated for
        // is_super_admin, even to be denied.
        if (! $user instanceof User || ! $user->isSuperAdmin()) {
            abort(404);
        }

        if (! $user->hasTwoFactorEnabled()) {
            return redirect()
                ->route('two-factor.enrol')
                ->with('status', 'Two-factor authentication is required for admin access.');
        }

        return $next($request);
    }
}
