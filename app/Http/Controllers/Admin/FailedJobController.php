<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The failed-job backlog.
 *
 * Publishing failures land here, so this is where a "my post never went out"
 * ticket gets answered. Payloads and exceptions are shown truncated and
 * escaped: a job payload can carry customer content, and an exception trace
 * can carry a connection string.
 */
final class FailedJobController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $request->user()->can('platform.jobs.view') || abort(403);

        $queue = trim((string) $request->query('queue', ''));

        $jobs = DB::table('failed_jobs')
            ->when($queue !== '', fn ($q) => $q->where('queue', $queue))
            ->orderByDesc('id')
            ->paginate((int) config('platform.per_page.failed_jobs', 25))
            ->withQueryString();

        $jobs->getCollection()->transform(function (object $job): object {
            $job->job_name = $this->jobName($job->payload);
            // The first line carries the class and message; the trace below it
            // is noise on a list screen and is where secrets tend to hide.
            $job->exception_summary = Str::limit(strtok((string) $job->exception, "\n") ?: '', 240);
            unset($job->payload, $job->exception);

            return $job;
        });

        return view('admin.jobs.index', [
            'title' => 'Failed jobs',
            'jobs' => $jobs,
            'queue' => $queue,
            'queues' => DB::table('failed_jobs')->distinct()->orderBy('queue')->pluck('queue')->all(),
            'pending' => (int) DB::table('jobs')->count(),
        ]);
    }

    /**
     * Re-dispatch one failed job.
     *
     * Audited because a retry is a real side effect: retrying a publish can
     * put a post on a customer's live account.
     */
    public function retry(Request $request, string $uuid): RedirectResponse
    {
        $request->user()->can('platform.jobs.view') || abort(403);

        $job = DB::table('failed_jobs')->where('uuid', $uuid)->first();

        abort_if($job === null, 404);

        Artisan::call('queue:retry', ['id' => [$uuid]]);

        $this->audit->log(
            'platform.job_retried',
            null,
            newValues: ['uuid' => $uuid, 'queue' => $job->queue],
            actor: $request->user(),
        );

        return back()->with('status', 'Job re-queued.');
    }

    /**
     * Pull a readable class name out of the serialized payload.
     *
     * Best-effort by design: a payload written by an older release may not
     * match today's shape, and an unreadable name must not break the page that
     * exists to diagnose failures.
     */
    private function jobName(string $payload): string
    {
        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            return 'unknown';
        }

        $name = $decoded['displayName'] ?? $decoded['job'] ?? null;

        return is_string($name) ? class_basename($name) : 'unknown';
    }
}
