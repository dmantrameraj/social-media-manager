<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates product access on tenant lifecycle state.
 *
 * Billing and renewal routes are deliberately excluded from the block, so a
 * suspended tenant can always pay to return. Locking someone out of the only
 * screen that could restore their account is how a recoverable billing lapse
 * becomes a churned customer. See docs/03-TENANCY.md §9.
 */
final class EnsureTenantActive
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->context->hasTenant()) {
            return $next($request);
        }

        $tenant = $this->context->get();

        if ($tenant->permitsProductAccess()) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(403, 'This workspace is not active.');
        }

        return redirect()
            ->route('billing.renew')
            ->with('tenant_status', $tenant->status->value);
    }
}
