<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agency;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Models\User;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * An agency reading its own audit trail.
 *
 * Every action staff take has been recorded since Phase 1 -- status changes,
 * credential edits, brand archival, retries, impersonation -- and the only
 * screen that read any of it was /admin/audit, behind `platform.audit.view`.
 * So a Super Admin could read any agency's trail and the agency itself could
 * not read its own. `audit_logs.view` has been granted to Agency Owner and
 * Agency Admin in the role catalogue since Step 5 and governed nothing.
 *
 * "Who archived that brand?" is the question this answers, and it was
 * unanswerable by the people it concerned.
 *
 * TENANCY IS EXPLICIT HERE, and that is the thing most easily got wrong in
 * this file. AuditLog deliberately does NOT use BelongsToTenant: a failed
 * login happens before any tenant is resolved and the row must still be
 * written, so there is no global scope to fall back on. Every query below
 * filters on the resolved tenant by hand, and the test suite asserts a rival
 * agency's entries are invisible.
 */
final class AuditLogController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request): View
    {
        $request->user()->can('audit_logs.view') || abort(403);

        $tenantId = $this->context->id();

        $validated = $request->validate([
            'action' => ['nullable', 'string', 'max:120'],
            'actor' => ['nullable', 'integer'],
        ]);

        $action = trim((string) ($validated['action'] ?? ''));
        $actorId = $validated['actor'] ?? null;

        $logs = AuditLog::query()
            // The scope, written out. Not optional, not inherited.
            ->where('tenant_id', $tenantId)
            ->when($action !== '', fn ($q) => $q->where('action', 'like', $action.'%'))
            ->when($actorId !== null, fn ($q) => $q->where('actor_id', (int) $actorId))
            ->orderByDesc('id')
            ->paginate((int) config('audit.per_page', 50))
            ->withQueryString();

        return view('agency.audit.index', [
            'title' => 'Activity log',
            'logs' => $logs,

            /*
             | Only this agency's own members are offered as actors. Listing
             | every user would leak the existence of staff at other agencies
             | through a filter dropdown, which is a quieter version of the
             | same leak the tenant scope exists to prevent.
             */
            'actors' => User::query()
                ->whereHas('tenants', fn ($q) => $q->whereKey($tenantId))
                ->orderBy('name')
                ->get(['users.id', 'users.name']),

            // Distinct actions THIS agency has actually produced, so the
            // filter never advertises something they have never done.
            'actions' => DB::table('audit_logs')
                ->where('tenant_id', $tenantId)
                ->distinct()
                ->orderBy('action')
                ->pluck('action')
                ->all(),

            /*
             | Names for the actors on THIS page, in one query.
             |
             | The admin screen renders "user #3", which is fine for somebody
             | debugging the platform and useless to an agency owner asking who
             | archived a brand. Resolved here rather than through a relation
             | so the list does not issue a query per row.
             */
            'actorNames' => User::query()
                ->whereIn('id', $logs->pluck('actor_id')->filter()->unique())
                ->pluck('name', 'id'),

            'filters' => [
                'action' => $action,
                'actor' => $actorId === null ? null : (int) $actorId,
            ],
        ]);
    }
}
