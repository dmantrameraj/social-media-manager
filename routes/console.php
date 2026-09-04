<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled tasks
|--------------------------------------------------------------------------
|
| Driven by ONE system cron entry, which the whole product depends on:
|
|   * * * * * cd /home/USER/app && php artisan schedule:run >> /dev/null 2>&1
|
| Confirm the correct PHP CLI binary path with the host -- it is frequently
| version-specific and differs from the web SAPI binary. A cron running the
| wrong PHP fails silently every minute.
|
| See docs/07-QUEUE-ARCHITECTURE.md section 4.
|
*/

/*
 | Hourly, not daily: tenants' billing anniversaries fall at all hours, and a
 | daily run would leave some accounts up to 23 hours past their transition.
 | The command is idempotent, so an overlapping or retried run is harmless.
 */
Schedule::command('billing:process-lifecycle')
    ->hourly()
    ->withoutOverlapping(30)
    ->runInBackground();

/*
 | Bounded queue worker for shared hosting.
 |
 |   --max-time=50      exits before the next cron tick, bounding overlap
 |   --max-jobs=50      caps memory growth in a long-lived PHP process
 |   --stop-when-empty  no idle process holding a shared-host slot
 |   --tries=1          retries are governed by domain state, not the worker
 |
 | Remove this entry on a VPS, where Supervisor keeps persistent workers.
 | See docs/07-QUEUE-ARCHITECTURE.md section 5.
 */
Schedule::command(
    'queue:work --queue=publishing,webhooks,notifications,ai,media,default '
    .'--stop-when-empty --max-time=50 --max-jobs=50 --tries=1 --sleep=1'
)
    ->everyMinute()
    ->withoutOverlapping(2)
    ->runInBackground();

/*
 | The dispatcher. docs/06-PUBLISHING-ENGINE.md §6: every minute, claiming and
 | queueing only -- it never calls a provider, so it finishes in milliseconds
 | and cannot be the thing that times out.
 |
 | Until this existed nothing drove the publishing engine at all: a scheduled
 | post stayed scheduled for ever, however complete the engine behind it was.
 |
 | withoutOverlapping because two ticks racing would both scan the same rows.
 | They could not double-publish -- claiming is atomic -- but the loser would
 | burn a tick doing nothing.
 */
Schedule::command('publishing:dispatch-due')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->runInBackground();

/*
 | Every ten minutes: a reservation stranded by a dead worker costs a tenant
 | real spending power, so it should not sit for an hour.
 */
/*
 | Renew grants before they expire.
 |
 | scopeNeedingRefresh(), the provider contract's refresh() and
 | social.refresh_lead_time were all written and nothing ran them, so a token
 | reached expiry and publishing simply began failing.
 |
 | Hourly, not per-minute: the lead time is a day, so a missed hour costs
 | nothing, and sweeping every minute would ask providers the same question
 | 1,440 times to get the same answer.
 */
Schedule::command('social:refresh-tokens')
    ->hourly()
    ->withoutOverlapping();

Schedule::command('ai:sweep-reservations')
    ->everyTenMinutes()
    ->withoutOverlapping(5)
    ->runInBackground();

/*
 | Hourly, not daily: autopilot cadences are per brand and spread across the
 | week, so a daily run would bunch every brand into one moment.
 */
Schedule::command('ai:run-autopilot')
    ->hourly()
    ->withoutOverlapping(30)
    ->runInBackground();

// Snapshots hold customer business content, so they age out on a schedule.
Schedule::command('ai:purge-snapshots')->dailyAt('03:40');

/*
 | Hourly, not daily, because tenant billing anniversaries fall on whatever
 | hour they signed up in -- a daily run would leave some tenants exhausted
 | for up to 23 extra hours every month. docs/08-AI-ARCHITECTURE.md §5 has
 | specified this since the ledger shipped; nothing scheduled it until now.
 */
Schedule::command('ai:reset-monthly-credits')
    ->hourly()
    ->withoutOverlapping(30)
    ->runInBackground();

// Corrects only the cached balance, never the ledger, so this can run without
// the caution a job touching real credit grants would need.
Schedule::command('ai:reconcile-credits')->dailyAt('04:20');

/*
 | Retention, warning first. config('tenancy.purge_warning_days') has held
 | [30, 7] since Phase 0 and nothing read it until this shipped -- by the time
 | a tenant is due, warning them is pointless.
 */
Schedule::command('platform:warn-pending-purge')
    ->dailyAt('04:00')
    ->withoutOverlapping(30);

/*
 | Billing has been stamping tenants.purge_after on cancellation since it
 | shipped and nothing consumed it, so the 60-day promise in
 | docs/10-SECURITY.md §9 was a date written into a column.
 |
 | Not in the background: this is the most destructive thing the application
 | does, and its output should land where schedule:run's own logging captures
 | it rather than in a detached process nobody reads.
 */
Schedule::command('platform:purge-expired-data')
    ->dailyAt('04:10')
    ->withoutOverlapping(60);

Schedule::command('queue:prune-failed --hours=336')->daily();
Schedule::command('model:prune')->daily();

/*
 | Every minute, and deliberately NOT in the background.
 |
 | This is the signal the operations dashboard reads to decide whether the
 | scheduler is alive at all, so it must not depend on a background process
 | that could fail separately from schedule:run itself. It also closes
 | impersonation sessions that outlived their ceiling.
 */
Schedule::command('platform:heartbeat')
    ->everyMinute()
    ->withoutOverlapping(2);
