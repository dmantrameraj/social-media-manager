<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Platform\Services\ResolveDomainService;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves which agency a portal request belongs to, from the hostname.
 *
 * THE PORTAL ONLY. ResolveTenant states the rule this class has to live
 * beside: "a tenant id arriving in a request body, query string, JSON payload,
 * header or hidden form field is IGNORED." A hostname IS a header, so this is
 * deliberately confined to the surface where it is safe.
 *
 * What makes it safe here:
 *
 *   1. Only VERIFIED domains resolve. An unverified row is a claim, and
 *      resolving on a claim would let anybody point DNS at us and be served
 *      another agency's portal.
 *
 *   2. It grants NOTHING. Resolving a host sets the branding context and
 *      nothing else -- a portal user's access still comes from their own
 *      brand assignments, checked per request as before.
 *
 *   3. It CONSTRAINS rather than widens. A portal user whose tenant does not
 *      match the resolved host is refused, so a custom domain narrows who can
 *      use it rather than opening a second door into everybody's data.
 *
 * The agency surface keeps session-based resolution. Privileged actions live
 * there, and nothing about a hostname should influence them.
 */
final class ResolvePortalHost
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly ResolveDomainService $domains,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        /*
         | The lookup itself lives in App\Domain\Platform, which the scope
         | bypass allow-list already covers: finding out which tenant a request
         | belongs to is cross-tenant by definition, and granting that to every
         | middleware would be a far wider permission than this needs.
         */
        $tenant = $this->domains->tenantFor($request->getHost());

        if ($tenant === null) {
            /*
             | The platform's own hostname, or one nobody has verified. Not an
             | error: the portal is reachable at the default host too, and
             | branding falls back to the platform's.
             */
            return $next($request);
        }

        $user = $request->user('customer');

        /*
         | A signed-in portal user on somebody else's domain is refused. This
         | is the constraint that makes host resolution safe rather than
         | merely convenient -- without it a verified domain would be a second
         | entrance to every tenant's portal.
         |
         | 404 rather than 403: on a hostname that is not theirs, the honest
         | answer is that there is nothing here for them.
         */
        if ($user !== null && (int) $user->tenant_id !== (int) $tenant->getKey()) {
            abort(404);
        }

        return $this->context->run($tenant, fn (): Response => $next($request));
    }
}
