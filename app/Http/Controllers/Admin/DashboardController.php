<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Platform\Models\ImpersonationSession;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Platform operations overview.
 *
 * Answers the two questions someone opens this page to ask: is the platform
 * healthy, and is the business healthy. Nothing here is tenant-scoped -- this
 * is the surface where global scopes are deliberately bypassed, which is why
 * it reads through explicit cross-tenant queries rather than through models
 * that would silently filter to whatever tenant happens to be in context.
 */
final class DashboardController extends Controller
{
    public function __invoke(): View
    {
        return view('admin.dashboard', [
            // Not 'Platform': the layout already appends it, and the tab
            // read "Platform . Platform . <app>".
            'title' => 'Overview',
            'tenants' => $this->tenantCounts(),
            'subscriptions' => $this->subscriptionCounts(),
            'queue' => $this->queueHealth(),
            'scheduler' => $this->schedulerHealth(),
            'openImpersonations' => ImpersonationSession::query()
                ->open()
                ->with('superAdmin')
                ->orderByDesc('started_at')
                ->limit(10)
                ->get(),
            'recentTenants' => Tenant::query()
                ->latest('created_at')
                ->limit(8)
                ->get(),
        ]);
    }

    /** @return array<string, int> */
    private function tenantCounts(): array
    {
        $byStatus = Tenant::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->all();

        $counts = ['total' => array_sum($byStatus)];

        foreach (TenantStatus::cases() as $status) {
            $counts[$status->value] = (int) ($byStatus[$status->value] ?? 0);
        }

        return $counts;
    }

    /** @return array<string, int> */
    private function subscriptionCounts(): array
    {
        return DB::table('subscriptions')
            ->whereNull('deleted_at')
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(static fn ($v): int => (int) $v)
            ->all();
    }

    /**
     * Depth of the queue and the size of the failure backlog.
     *
     * Read straight from the jobs tables rather than from a metrics service,
     * because the point of this panel is to work when everything else is
     * broken.
     *
     * @return array{pending: int, reserved: int, failed: int, oldest_wait_seconds: int|null, warning: bool}
     */
    private function queueHealth(): array
    {
        $pending = (int) DB::table('jobs')->count();
        $reserved = (int) DB::table('jobs')->whereNotNull('reserved_at')->count();
        $failed = (int) DB::table('failed_jobs')->count();

        // available_at is a unix timestamp; the oldest one still waiting is a
        // far better signal than raw depth, since a deep queue that is moving
        // is fine and a shallow one that is stuck is not.
        $oldest = DB::table('jobs')->min('available_at');

        return [
            'pending' => $pending,
            'reserved' => $reserved,
            'failed' => $failed,
            'oldest_wait_seconds' => $oldest === null ? null : max(0, time() - (int) $oldest),
            'warning' => $pending > (int) config('platform.health.queue_depth_warning', 100),
        ];
    }

    /**
     * Whether the scheduler is actually running.
     *
     * Publishing, credit resets and the reservation sweeper are all scheduled
     * work, so a dead scheduler is silent: nothing errors, things simply stop
     * happening. The heartbeat is written by a scheduled command; its absence
     * is the alarm.
     *
     * @return array{last_beat: Carbon|null, stale: bool, threshold_minutes: int}
     */
    private function schedulerHealth(): array
    {
        $key = (string) config('platform.health.cache_key', 'platform:scheduler:heartbeat');
        $threshold = (int) config('platform.health.scheduler_stale_minutes', 5);

        $raw = Cache::get($key);
        $beat = is_string($raw) ? Carbon::parse($raw) : null;

        return [
            'last_beat' => $beat,
            // Never beaten counts as stale: a scheduler that has not run since
            // deploy is exactly the case this panel exists to catch.
            'stale' => $beat === null || $beat->lt(now()->subMinutes($threshold)),
            'threshold_minutes' => $threshold,
        ];
    }
}
