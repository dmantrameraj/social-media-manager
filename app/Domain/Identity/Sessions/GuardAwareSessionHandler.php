<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sessions;

use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Support\Facades\Auth;

/**
 * Records WHICH guard a session belongs to, not just the user id.
 *
 * `sessions.guard` has existed since the first migration with a comment saying
 * a custom handler populates it. None did, so the column was always null and
 * device listing could not be built on it.
 *
 * Laravel's own handler asks `$container->make(Guard::class)` for the id, which
 * resolves the DEFAULT guard -- `web`. This application has two: agency staff on
 * `web` and clients on `customer`, in separate tables with overlapping ids. So
 * the stock handler records a null user for every portal session, and worse,
 * `user_id = 7` would be ambiguous between a staff member and a client if it
 * ever were populated for both. The guard is what disambiguates it.
 */
final class GuardAwareSessionHandler extends DatabaseSessionHandler
{
    /**
     * @param  array<string, mixed>  $payload
     * @return $this
     */
    protected function addUserInformation(&$payload)
    {
        foreach ((array) config('session.tracked_guards', []) as $guard) {
            if (! Auth::guard($guard)->check()) {
                continue;
            }

            $payload['user_id'] = Auth::guard($guard)->id();
            $payload['guard'] = $guard;

            return $this;
        }

        /*
         | Explicitly nulled rather than left alone. A session row is rewritten
         | on every request, including after logout -- leaving the previous
         | values in place would keep a signed-out session listed as an active
         | device, which is precisely the thing somebody checks this screen to
         | rule out.
         */
        $payload['user_id'] = null;
        $payload['guard'] = null;

        return $this;
    }
}
