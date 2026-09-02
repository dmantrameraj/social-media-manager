<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Cross-tenant audit trail.
 *
 * Values were already redacted by SecretRedactor on the way in, so nothing
 * here can print a token even if a future writer forgets. That is the correct
 * place for the guarantee -- a viewer that redacts on read leaves the secret
 * sitting in the database for anything else to find.
 */
final class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        $request->user()->can('platform.audit.view') || abort(403);

        $tenantId = $request->query('tenant');
        $action = trim((string) $request->query('action', ''));
        $actorId = $request->query('actor');

        $logs = AuditLog::query()
            ->when(is_numeric($tenantId), fn ($q) => $q->where('tenant_id', (int) $tenantId))
            ->when(is_numeric($actorId), fn ($q) => $q->where('actor_id', (int) $actorId))
            ->when($action !== '', fn ($q) => $q->where('action', 'like', $action.'%'))
            ->orderByDesc('id')
            ->paginate((int) config('platform.per_page.audit_logs', 50))
            ->withQueryString();

        return view('admin.audit.index', [
            'title' => 'Audit log',
            'logs' => $logs,
            'tenants' => Tenant::query()->orderBy('name')->get(['id', 'name']),
            'filters' => [
                'tenant' => is_numeric($tenantId) ? (int) $tenantId : null,
                'action' => $action,
                'actor' => is_numeric($actorId) ? (int) $actorId : null,
            ],
            // Distinct actions make the filter discoverable; there are a few
            // dozen, not thousands, so this stays cheap.
            'actions' => DB::table('audit_logs')
                ->distinct()
                ->orderBy('action')
                ->pluck('action')
                ->all(),
        ]);
    }
}
