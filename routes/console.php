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

Schedule::command('queue:prune-failed --hours=336')->daily();
Schedule::command('model:prune')->daily();
