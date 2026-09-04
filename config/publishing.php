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

    /*
     | Time of day used when a post with no time yet is dropped onto a calendar
     | day. A drag says WHICH DAY, never which minute, so the minute has to come
     | from somewhere -- and it has to be the same somewhere every time, or two
     | identical drags produce two different schedules.
     |
     | Read in the brand timezone, not UTC.
     */
    'default_schedule_time' => env('PUBLISHING_DEFAULT_SCHEDULE_TIME', '09:00'),

    'import' => [
        /*
         | Most rows one upload may carry.
         |
         | A bound rather than a judgement about how much content an agency
         | plans: every row is a post, targets and an audit entry, and an
         | unbounded file is a cheap way to make one request do an unbounded
         | amount of work.
         */
        'max_rows' => (int) env('PUBLISHING_IMPORT_MAX_ROWS', 500),
    ],

    'recurrence_horizon_days' => 60,

];
