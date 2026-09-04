<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Analytics collection
|--------------------------------------------------------------------------
|
| Phase 5. The storage, normalisation and collection driver are here; the
| per-network MAPPING is not, and deliberately so.
|
| Every provider names its metrics differently, and what each name counts
| differs too -- one platform's "reach" is not another's. Translating a
| provider's vocabulary into the normalised columns is an adapter's job, done
| against that provider's live documentation, because a wrong mapping is
| invisible: a number that is merely wrong still looks like a number, and an
| agency would report it to their client as fact.
|
| See docs/05-SOCIAL-PROVIDERS.md §4 and §64 of the master prompt.
|
*/

return [

    /*
     | How long a published post stays worth re-polling.
     |
     | Engagement on most networks is effectively finished well inside a month,
     | and polling older posts spends rate limit re-reading numbers that no
     | longer move.
     */
    'window_days' => (int) env('ANALYTICS_WINDOW_DAYS', 30),

    /*
     | Most targets polled in one pass. Bounded so a backlog cannot turn a
     | single scheduled run into an hour of provider calls -- the same reason
     | publishing and token refresh are bounded.
     */
    'collect_batch_size' => (int) env('ANALYTICS_COLLECT_BATCH_SIZE', 200),

    /*
     | How long raw provider payloads are kept.
     |
     | The raw column exists so a renamed or newly-discovered metric can be
     | backfilled without re-polling an API that has already aged the data out.
     | That value decays: after a year, the normalised columns are the history
     | and the raw payload is mostly storage.
     |
     | Null keeps them for ever.
     */
    'raw_retention_days' => env('ANALYTICS_RAW_RETENTION_DAYS', 400),

    /*
     | How long a monthly report link stays open.
     |
     | Long enough that a client returning to the email six weeks later still
     | finds it working; short enough that an old inbox is not a standing
     | window into a business's performance.
     */
    'monthly_report_expiry_days' => (int) env('ANALYTICS_MONTHLY_REPORT_EXPIRY_DAYS', 60),

];
