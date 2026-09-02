<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Impersonation
    |--------------------------------------------------------------------------
    |
    | Support access to an agency's account. Every value here is a safety
    | boundary rather than a preference: see docs/04-AUTH-RBAC.md §9.
    |
    */

    'impersonation' => [

        /*
         | Hard ceiling on one session. An impersonation left open is an
         | unattended key to someone else's account, so it expires whether or
         | not the admin remembers to exit.
         */
        'timeout_minutes' => (int) env('IMPERSONATION_TIMEOUT_MINUTES', 60),

        /*
         | Route name patterns refused while impersonating.
         |
         | These are actions a support engineer must never take *as* the
         | customer: changing how the customer authenticates, reading or
         | rotating their provider credentials, destroying their content, or
         | spending their money. The customer cannot see it happen and would
         | have no way to distinguish it from their own action afterwards.
         |
         | Matched with Str::is(), plus one extra rule in HandleImpersonation:
         | a trailing `.*` also matches the bare parent name, so
         | `agency.billing.*` covers the route named `agency.billing`. Without
         | that, the pattern silently protected nothing.
         |
         | Every pattern here MUST match at least one registered route -- a test
         | asserts it. A pattern that matches nothing reads as a protection and
         | is a hole.
         */
        'blocked_routes' => [
            // Credentials and authentication.
            'user-profile-information.update',
            'user-password.update',
            'password.*',
            'two-factor.*',

            // Money.
            'agency.billing.*',
            'billing.*',

            // Destruction.
            '*.destroy',
            'agency.brands.archive',
        ],

        /*
         | Patterns for routes that do not exist yet.
         |
         | Kept separate and deliberately NOT covered by the "matches something"
         | test, because they cannot match until the feature ships. They are
         | merged into the live list at match time, so the protection is in
         | force the moment those routes are registered rather than depending on
         | somebody remembering to come back here.
         */
        'blocked_routes_pending' => [
            // Phase 2 -- provider credentials and connections.
            'agency.social.credentials.*',
            'agency.social.*.disconnect',

            // Team removal and subscription changes.
            'agency.team.remove',
            'agency.subscription.*',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard thresholds
    |--------------------------------------------------------------------------
    |
    | When the operations dashboard should say something is wrong. These are
    | display thresholds only -- nothing here gates behaviour.
    |
    */

    'health' => [
        // The scheduler writes a heartbeat every minute; more than this many
        // minutes of silence means it is not running.
        'scheduler_stale_minutes' => (int) env('SCHEDULER_STALE_MINUTES', 5),

        // Pending jobs above this suggest workers are not keeping up.
        'queue_depth_warning' => (int) env('QUEUE_DEPTH_WARNING', 100),

        'cache_key' => 'platform:scheduler:heartbeat',
    ],

    /*
    |--------------------------------------------------------------------------
    | Listing sizes
    |--------------------------------------------------------------------------
    */

    'per_page' => [
        'tenants' => 25,
        'audit_logs' => 50,
        'failed_jobs' => 25,
    ],

];
