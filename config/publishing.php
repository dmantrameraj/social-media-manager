<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Retry
    |--------------------------------------------------------------------------
    |
    | Retry state lives in post_targets.next_attempt_at rather than in the
    | queue's own delayed-job mechanism. Slightly more code, but the schedule
    | is inspectable, operator-editable, and identical on the database and
    | Redis drivers. See docs/06-PUBLISHING-ENGINE.md §8.
    |
    */

    'max_attempts' => (int) env('PUBLISHING_MAX_ATTEMPTS', 3),

    // Seconds: 1 min, 5 min, 15 min.
    'backoff' => [60, 300, 900],

    /*
     | How long a claim may be held before the target is considered stale and a
     | worker presumed dead. Must exceed the slowest realistic publish.
     */
    'lock_ttl' => (int) env('PUBLISHING_LOCK_TTL', 900),

    /*
    |--------------------------------------------------------------------------
    | Dispatch
    |--------------------------------------------------------------------------
    */

    'dispatch_batch_size' => (int) env('PUBLISHING_DISPATCH_BATCH', 200),

    /*
     | The queue PublishPostTarget runs on. Named separately from the default
     | so a backlog of posts cannot starve notifications or media processing --
     | the worker in routes/console.php lists it first for the same reason.
     */
    'queue' => env('PUBLISHING_QUEUE', 'publishing'),

    /*
     | Minimum lead time when scheduling, so a post cannot be created into the
     | same second the sweeper is already scanning.
     */
    'min_lead_seconds' => 60,

    /*
     | Per-job time budget for chunked work (resumable uploads). Kept below the
     | shared-hosting worker's --max-time so a job checkpoints and re-dispatches
     | rather than being killed mid-upload.
     */
    'job_time_budget' => (int) env('PUBLISHING_JOB_TIME_BUDGET', 35),

    'recurrence_horizon_days' => 60,

];
