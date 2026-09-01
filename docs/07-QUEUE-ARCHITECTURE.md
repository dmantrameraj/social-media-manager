# 07 — Queue & Scheduler Architecture

## 1. Constraint

V1 launches on **Hostinger shared hosting**: no root, no Supervisor, no guaranteed Redis,
no long-running daemons, and PHP CLI processes subject to time limits. The architecture must
work there and move to a VPS by changing configuration, not code.

| | Shared hosting (V1) | VPS (later) |
|---|---|---|
| Queue driver | `database` | `redis` |
| Workers | Cron-invoked, bounded lifetime | Supervisor, persistent |
| Cache | `database` | `redis` |
| Scheduler | System cron -> `schedule:run` | Same |
| Monitoring | DB queries + admin screen | Horizon |

## 2. What makes migration a config change

- Only Laravel queue abstractions are used: `dispatch()`, `ShouldQueue`, `Bus::batch()`,
  `->onQueue()`, `->delay()`.
- No driver-specific calls. No `Redis::` facade in business logic. No Horizon-specific
  attributes.
- Retry scheduling lives in `post_targets.next_attempt_at`, not in driver-managed delayed
  jobs, so retry behaviour is identical on both drivers and remains inspectable
  (`06-PUBLISHING-ENGINE.md` §8).
- Queue names are read from `config/queue.php` connections, never hardcoded strings
  scattered through job classes.

Migration is then: change `QUEUE_CONNECTION` and `CACHE_STORE`, start Supervisor with the
existing queue names, remove the cron-driven worker entry. No job class changes.

## 3. Queues and priorities

| Queue | Contents | Priority | Notes |
|---|---|---|---|
| `publishing` | `PublishPostTarget` | highest | Time-critical; scheduled posts must go out on time |
| `notifications` | Mail, in-app | high | Fast, cheap |
| `media` | Thumbnails, image variants | normal | Heavier |
| `ai` | Generation jobs | normal | External latency, seconds to a minute |
| `webhooks` | Inbound webhook processing | high | Must drain fast to avoid backlog |
| `default` | Token refresh, health checks, housekeeping | low | |
| `reports` | Phase 5 | low | |

Worker order on shared hosting:
`publishing,webhooks,notifications,ai,media,default`

## 4. Scheduler

One system cron entry — the only one required:

```bash
* * * * * cd /home/USER/app && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

`routes/console.php`:

```php
// Dispatch due publications
Schedule::command('publishing:dispatch-due')
    ->everyMinute()->withoutOverlapping(5)->runInBackground();

// Bounded queue worker (shared hosting only; see §5)
Schedule::command('queue:work --queue=publishing,webhooks,notifications,ai,media,default '
        .'--stop-when-empty --max-time=50 --max-jobs=50 --tries=1 --sleep=1')
    ->everyMinute()->withoutOverlapping(2)->runInBackground();

Schedule::command('publishing:recover-stale-locks')->everyFiveMinutes();
Schedule::command('social:refresh-tokens')->everyThirtyMinutes();
Schedule::command('social:health-check')->dailyAt('03:15');
Schedule::command('billing:process-lifecycle')->hourly();   // trial/grace/expiry
Schedule::command('ai:reset-monthly-credits')->hourly();    // per-tenant period boundaries
Schedule::command('publishing:materialise-recurring')->dailyAt('02:00');
Schedule::command('platform:prune-oauth-states')->hourly();
Schedule::command('platform:purge-expired-data')->dailyAt('04:00');
Schedule::command('queue:prune-failed --hours=336')->daily();
Schedule::command('model:prune')->daily();
```

`withoutOverlapping` uses the cache lock store. On shared hosting that is the database
cache, which is exactly why `cache_locks` must exist and why the cache driver cannot be
`array`.

## 5. Bounded worker strategy (shared hosting)

`queue:work --stop-when-empty --max-time=50 --max-jobs=50` starts each minute, drains what
it can, and exits before the next tick. Rationale for each flag:

- `--max-time=50` — exits before the next cron tick, so overlap is bounded even if
  `withoutOverlapping` misbehaves.
- `--max-jobs=50` — caps memory growth in a long-lived PHP process.
- `--stop-when-empty` — no idle process burning a shared-host process slot.
- `--tries=1` — retries are governed by `next_attempt_at`, not by the worker.
- `--sleep=1` — minimal idle polling within the window.

Accepted consequences, and why they are tolerable:

| Consequence | Impact | Mitigation |
|---|---|---|
| Up to ~60s dispatch latency | A post scheduled 09:00 may publish 09:00:45 | Documented; social scheduling has no sub-minute expectations |
| Single concurrent worker | Throughput ceiling | Publishing jobs are I/O-bound and short; measured headroom below |
| A job longer than 50s is killed mid-flight | Stale lock | Stale-lock recovery with verification (`06` §7); chunked uploads resume (§7 below) |
| Host may kill CLI processes | Same as above | Same |

**Capacity estimate.** A publish is roughly 2–5s of provider I/O. At ~45 usable seconds per
minute, one worker handles roughly 10–20 publishes/minute, or 600–1,200/hour. For early
tenants with posts clustered at 09:00, a spike of a few hundred targets clears in well under
an hour — acceptable, but it is the first thing that will break at scale. **The trigger for
VPS migration is a sustained dispatch-to-publish p95 above 5 minutes**, tracked from
`post_targets.scheduled_at` vs `published_at` on the admin queue-health screen.

If the host permits multiple cron entries, a second staggered worker on
`--queue=publishing` only, offset by 30 seconds, roughly doubles publishing throughput
without touching application code.

## 6. Database queue considerations

- `jobs` uses `SELECT … FOR UPDATE SKIP LOCKED` on MySQL 8, so multiple workers are safe.
  MySQL 5.7 lacks `SKIP LOCKED` — **MySQL 8.0+ is a hard requirement**, not a preference.
- `jobs` is high-churn; monitor its size on the admin screen. Sustained growth means the
  worker is not keeping up.
- `failed_jobs` is pruned at 14 days, after the failure has been surfaced in the product.
- `job_batches` supports CSV import progress reporting.
- Payloads carry IDs only, never serialised models or media contents. A fat payload makes
  the `jobs` table the bottleneck.

## 7. Long-running work

YouTube uploads and video processing do not fit a 50-second window.

**Chunked, resumable pattern** — the job uploads chunks until it approaches its time budget,
persists the resumable session handle and byte offset to `post_targets.meta`, then
re-dispatches itself with a short delay:

```php
public function handle(): void
{
    $deadline = now()->addSeconds(config('publishing.job_time_budget')); // 35s

    while (now()->lt($deadline) && $this->upload->hasMoreChunks()) {
        $this->upload->sendNextChunk();
        $this->persistProgress();
    }

    if ($this->upload->hasMoreChunks()) {
        self::dispatch($this->tenantId, $this->targetId)->delay(now()->addSeconds(5));
        return;
    }

    $this->finalise();
}
```

The upload session handle is the idempotency anchor: a re-dispatch resumes the same session
and cannot create a second video.

**Video transcoding is deferred entirely.** No FFmpeg dependency ships in V1. Users upload
platform-ready video, and validation rejects files that do not meet the target platform's
requirements with a clear message. Transcoding arrives with the VPS.

Image processing (thumbnails, variants) uses GD via Intervention — confirmed available in
the local PHP build — and runs on the `media` queue in well under the time budget.

## 8. Failure handling and visibility

- Every job declares `$tries`, `$backoff`, `$timeout` and `$maxExceptions` explicitly.
  Defaults are not relied upon.
- `failed()` writes a domain-level failure record (e.g. `post_targets.last_error_*`), so
  product state never depends on someone reading `failed_jobs`.
- Failures are surfaced **in the product** where the user can act, not only in an admin
  table.
- Jobs are idempotent by design: re-running a completed job is a no-op, verified by a
  status guard at the top of `handle()`.
- Unique jobs use `ShouldBeUnique` where duplicate dispatch is possible
  (token refresh per connection, monthly credit reset per tenant).

## 9. Admin queue health

`/admin/jobs` shows:

- Pending jobs by queue; oldest pending age
- Failed jobs (24h / 7d) with retry and delete actions
- **Dispatch-to-publish p95** — the metric that decides VPS migration timing
- Targets stuck in `processing` beyond the lock TTL
- Last successful run per scheduled command (from a `scheduled_task_runs` record written by
  a scheduler event hook)
- Connections in `needs_reconnect`, aggregated by tenant

The "last successful run per command" row is what detects the most likely production
failure on shared hosting: **the cron entry silently stopped**. Without it, nothing
publishes and nothing reports an error.

## 10. VPS migration

```ini
# /etc/supervisor/conf.d/app-worker.conf
[program:app-publishing]
command=php /var/www/app/artisan queue:work redis --queue=publishing --sleep=1 --tries=1 --max-time=3600
numprocs=4
autostart=true
autorestart=true
stopwaitsecs=60

[program:app-general]
command=php /var/www/app/artisan queue:work redis --queue=webhooks,notifications,ai,media,default --sleep=3 --tries=1 --max-time=3600
numprocs=2
```

Migration steps:

1. Provision Redis; set `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`,
   `SESSION_DRIVER=redis`.
2. Drain the database queue **before** switching (`queue:work database --stop-when-empty`)
   so no job is stranded in the old driver.
3. Remove the `queue:work` scheduler entry; keep the `schedule:run` cron.
4. Start Supervisor; install Horizon for observability.
5. Raise `dispatch_batch_size` and `job_time_budget` in `config/publishing.php`.
6. Enable video transcoding once FFmpeg is installed.

No application code changes. That property is the point of this document, and it is
verified by a test that asserts no driver-specific class is referenced outside
`config/`.
