<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Engagement (unified inbox)
|--------------------------------------------------------------------------
|
| Phase 7. The storage, sync and reply machinery are here; the per-network
| FETCHING is not, and deliberately so.
|
| Which endpoint returns a conversation, what a thread id looks like, and how
| long a platform allows a reply are all provider specifics that must be
| verified against live documentation. An adapter implements SupportsInbox
| against that; nothing above it knows a provider's field names.
|
| See docs/05-SOCIAL-PROVIDERS.md §4 and §64 of the master prompt.
|
*/

return [

    /*
     | Most accounts polled in one pass. Bounded for the same reason
     | publishing, token refresh and analytics collection are bounded: a
     | backlog must not turn one scheduled run into an hour of provider calls.
     */
    'sync_batch_size' => (int) env('ENGAGEMENT_SYNC_BATCH_SIZE', 100),

    /*
     | How long an outbound reply may sit pending before it is treated as
     | failed.
     |
     | A reply stuck "sending" for ever is the state that makes somebody assume
     | it went out. Better to say plainly that it did not.
     */
    'reply_pending_timeout' => (int) env('ENGAGEMENT_REPLY_TIMEOUT', 900),

];
