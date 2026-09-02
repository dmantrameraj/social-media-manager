<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Platform\Services\ImpersonationService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces the two limits on an impersonated session: what it may do, and how
 * long it may last.
 *
 * Runs on the whole `web` group rather than on the agency group alone. A
 * restriction that only applies to the routes someone remembered to protect is
 * not a restriction, and the timeout has to hold everywhere -- including on a
 * route added later by someone who has never read this file.
 */
final class HandleImpersonation
{
    public function __construct(private readonly ImpersonationService $impersonation) {}

    public function handle(Request $request, Closure $next): Response
    {
        $session = $request->session();

        if (! $this->impersonation->isImpersonating($session)) {
            return $next($request);
        }

        /*
         | Expiry is checked per request rather than by a scheduled sweeper
         | alone. A sweeper closes the database row, but only this can stop the
         | browser session that is still holding the customer's account open.
         */
        $record = $this->impersonation->current($session);

        if ($record === null || $record->hasExpired()) {
            $this->impersonation->stop($session, 'impersonation.expired');

            return redirect()
                ->route('admin.dashboard')
                ->with('status', 'The impersonation session expired and has been closed.');
        }

        if ($this->isBlocked($request)) {
            abort(403, 'This action is not available while impersonating.');
        }

        return $next($request);
    }

    /**
     * Blocked actions are matched on ROUTE NAME, not on URL or verb.
     *
     * A URL prefix check would silently stop covering an action the moment it
     * moved, and would not distinguish reading a billing page from submitting
     * a payment. Route names are stable and are what the patterns in
     * config/platform.php are written against.
     */
    private function isBlocked(Request $request): bool
    {
        $name = $request->route()?->getName();

        if ($name === null) {
            // An unnamed route cannot be matched against the block list, so it
            // cannot be shown to be safe either. Reads are allowed through;
            // anything that could write is not.
            return ! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true);
        }

        // Leaving impersonation must never be blocked by the impersonation
        // rules -- that would trap the admin inside the customer's account.
        if ($name === 'admin.impersonation.stop') {
            return false;
        }

        $patterns = array_merge(
            (array) config('platform.impersonation.blocked_routes', []),
            (array) config('platform.impersonation.blocked_routes_pending', []),
        );

        foreach ($patterns as $pattern) {
            if ($this->matches((string) $pattern, $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * `agency.billing.*` is meant to cover the route named `agency.billing`
     * too, and Str::is alone does not: the pattern compiles to a regex that
     * requires the separating dot, so the bare parent name slipped through and
     * the billing page stayed reachable while impersonating.
     *
     * A trailing `.*` therefore means "this route and everything under it".
     */
    private function matches(string $pattern, string $name): bool
    {
        if (Str::is($pattern, $name)) {
            return true;
        }

        return str_ends_with($pattern, '.*') && $name === substr($pattern, 0, -2);
    }
}
