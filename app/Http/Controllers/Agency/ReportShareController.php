<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agency;

use App\Domain\Analytics\Models\ReportShare;
use App\Domain\Analytics\Services\BuildReportService;
use App\Domain\Audit\AuditLogger;
use App\Domain\Customers\Models\Customer;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Getting a report out of the product: as a file, or as a link.
 *
 * `reports.generate` and `reports.share` have been in the permission
 * catalogue since Step 5 governing nothing. An agency could see figures on a
 * screen and had no way to hand them to the client who paid for the work,
 * which is most of what an agency does with them.
 */
final class ReportShareController extends Controller
{
    /** Longest a link may live. */
    private const MAX_EXPIRY_DAYS = 90;

    public function __construct(
        private readonly TenantContext $context,
        private readonly BuildReportService $reports,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * The same figures as the dashboard, as a spreadsheet.
     */
    public function export(Request $request): StreamedResponse
    {
        $request->user()->can('reports.generate') || abort(403);

        [$brand, $from, $to] = $this->window($request);

        $metrics = $this->reports->metrics([$brand->getKey()], $from, $to);
        $rows = $this->reports->rows($metrics);
        $columns = $this->reports->columns();

        $filename = sprintf(
            '%s-%s-to-%s.csv',
            str($brand->name)->slug(),
            $from->toDateString(),
            $to->toDateString(),
        );

        /*
         | Streamed rather than built in memory. A year of a busy brand is a
         | lot of rows, and an export that works for a small client and
         | exhausts memory for a large one is worse than none.
         */
        return response()->streamDownload(function () use ($rows, $columns): void {
            $out = fopen('php://output', 'wb');

            // Excel reads a bare UTF-8 CSV as the system codepage and mangles
            // any non-ASCII brand name. The BOM is what stops that.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, $columns);

            foreach ($rows as $row) {
                fputcsv($out, array_map(
                    // Null stays blank rather than becoming 0 -- unmeasured and
                    // zero mean different things in a client's spreadsheet.
                    static fn (mixed $v): string => $v === null ? '' : (string) $v,
                    $row,
                ));
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Mint a link the client can open without an account.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->user()->can('reports.share') || abort(403);

        [$brand, $from, $to] = $this->window($request);

        $days = (int) $request->validate([
            'expires_in_days' => ['required', 'integer', 'min:1', 'max:'.self::MAX_EXPIRY_DAYS],
        ])['expires_in_days'];

        ['token' => $token, 'hash' => $hash] = ReportShare::newToken();

        $share = new ReportShare;

        $share->forceFill([
            'tenant_id' => $this->context->get()->getKey(),
            'customer_id' => $brand->getKey(),
            'token_hash' => $hash,
            /*
             | The window is frozen here. "Last 30 days" evaluated on view
             | would mean a link sent in January quietly shows April's numbers,
             | and the client reads a report nobody at the agency ever saw.
             */
            'window_from' => $from,
            'window_to' => $to,
            'expires_at' => now()->addDays($days),
            'created_by_user_id' => $request->user()->getKey(),
        ])->save();

        $this->audit->log(
            'report.shared',
            $share,
            // The token is never in the audit entry. An audit log that records
            // working credentials is a second copy of the secret.
            newValues: ['customer_id' => $brand->getKey(), 'expires_at' => $share->expires_at->toIso8601String()],
            actor: $request->user(),
            tenantId: $share->tenant_id,
        );

        /*
         | Shown once, in the flash. The plaintext is never stored, so this is
         | the only moment it exists -- which is the point, and is why the
         | message says so.
         */
        return back()
            ->with('status', 'Share link created. Copy it now: it is not shown again.')
            ->with('share_url', route('reports.shared', $token));
    }

    public function revoke(Request $request, ReportShare $share): RedirectResponse
    {
        $request->user()->can('reports.share') || abort(403);

        // The tenant scope already hides another agency's share.
        abort_unless($share->tenant_id === $this->context->id(), 404);

        $share->forceFill(['revoked_at' => now()])->save();

        $this->audit->log(
            'report.share_revoked',
            $share,
            actor: $request->user(),
            tenantId: $share->tenant_id,
        );

        return back()->with('status', 'That link no longer works.');
    }

    /**
     * The brand and period a request is asking about.
     *
     * @return array{0: Customer, 1: Carbon, 2: Carbon}
     */
    private function window(Request $request): array
    {
        $validated = $request->validate([
            'brand' => ['required', 'integer'],
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $brand = Customer::query()->find($validated['brand']);

        // The tenant scope hides another agency's brand, so a foreign id and a
        // deleted one are indistinguishable from outside.
        abort_if($brand === null, 404);

        $request->user()->can('view', $brand) || abort(403);

        $to = Carbon::now();

        return [$brand, $to->copy()->subDays((int) ($validated['days'] ?? 30)), $to];
    }
}
