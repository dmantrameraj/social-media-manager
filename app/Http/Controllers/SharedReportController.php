<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Analytics\Services\BuildReportService;
use App\Domain\Platform\Services\ResolveReportShareService;
use App\Support\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * A shared report, opened by somebody with no account.
 *
 * The only unauthenticated surface in the product that shows tenant data, so
 * what it will NOT do matters more than what it does:
 *
 *   - It takes no brand, no period and no filter from the request. Everything
 *     shown is fixed in the row the token resolves to, so a leaked link cannot
 *     be edited into a wider one.
 *   - It resolves by HASH. The plaintext token is never stored, so a database
 *     read does not yield a working link.
 *   - Expiry and revocation are both fatal, and a stale link is a 404 rather
 *     than a message confirming it once existed.
 */
final class SharedReportController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly BuildReportService $reports,
        private readonly ResolveReportShareService $shares,
    ) {}

    public function __invoke(Request $request, string $token): View
    {
        /*
         | Resolved by HASH, in a service that also refuses expired and revoked
         | links -- so a caller cannot forget one of the two.
         |
         | One 404 for every failure: unknown, expired, revoked. Telling the
         | holder of a dead link which of those it is confirms that a report
         | for some client exists, which is more than a stranger should learn.
         */
        $share = $this->shares->forToken($token);

        abort_if($share === null, 404);

        /*
         | tenant_id is a non-nullable FK that cascades on delete, so the
         | tenant is always there -- a share whose agency was purged has been
         | deleted with it.
         */
        return $this->context->run($share->tenant, function () use ($share): View {
            /*
             | Counted, not logged. Enough to answer "did the client open it?"
             | without keeping an access log of an unauthenticated endpoint,
             | which is a privacy question of its own.
             */
            $share->forceFill([
                'view_count' => $share->view_count + 1,
                'last_viewed_at' => now(),
            ])->save();

            $metrics = $this->reports->metrics(
                [$share->customer_id],
                $share->window_from,
                $share->window_to,
            );

            return view('shared.report', [
                'title' => 'Report',
                'share' => $share,
                'brand' => $share->customer,
                'totals' => $this->reports->totals($metrics),
                'rows' => $this->reports->rows($metrics),
                'from' => $share->window_from,
                'to' => $share->window_to,
            ]);
        });
    }
}
