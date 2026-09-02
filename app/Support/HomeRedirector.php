<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Identity\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Where a signed-in principal belongs.
 *
 * Fortify's `home` config is a single static path, which is wrong the moment
 * the application has more than one surface: platform staff who belong to no
 * agency were sent to /app, where ResolveTenant answered 403. Login succeeded
 * and landed on a dead end, with no way to reach /admin except by typing the
 * URL.
 *
 * One class rather than a closure in three places, because the login response,
 * the two-factor login response and the root route must all agree -- a user
 * who lands somewhere different depending on which door they came through is a
 * support ticket.
 */
final class HomeRedirector
{
    public function pathFor(?Authenticatable $user): string
    {
        /*
         | Super Admins go to the platform surface.
         |
         | It is their primary workspace, and crucially most of them are members
         | of no agency at all, so /app cannot resolve a tenant for them. The
         | admin layout carries a "Leave platform admin" link for the minority
         | who also run an agency of their own.
         |
         | An unenrolled admin is bounced from here to two-factor enrolment,
         | which is the correct destination rather than a detour.
         */
        if ($user instanceof User && $user->isSuperAdmin()) {
            return route('admin.dashboard', absolute: false);
        }

        return route('agency.dashboard', absolute: false);
    }
}
