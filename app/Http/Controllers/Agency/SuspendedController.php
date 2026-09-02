<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agency;

use App\Support\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Where EnsureTenantActive sends a blocked tenant.
 *
 * Deliberately requires NO permission. Billing itself is gated on
 * billing.view, so redirecting everyone there would bounce a Designer between
 * a 403 and a redirect with no way out. This page tells any member what has
 * happened, and offers the billing link only to someone who can act on it.
 */
final class SuspendedController
{
    public function __invoke(TenantContext $context): View|RedirectResponse
    {
        $tenant = $context->get();

        /*
         | This route is outside the tenant.active group -- it has to be, or a
         | blocked tenant could not reach it -- which means it also answers for
         | tenants that are perfectly fine. Rendering unconditionally told a
         | trialing workspace it was paused and that publishing was unavailable,
         | neither of which was true.
         |
         | It doubles as the return path: once a lapsed tenant pays, a stale tab
         | or bookmark on this page puts them back into the product instead of
         | insisting they are still locked out.
         */
        if ($tenant->permitsProductAccess()) {
            return redirect()->route('agency.dashboard');
        }

        return view('agency.suspended', [
            'title' => 'Workspace paused',
            'tenant' => $tenant,
        ]);
    }
}
