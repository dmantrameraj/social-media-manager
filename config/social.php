<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Social provider registry
|--------------------------------------------------------------------------
|
| Capabilities and limits live here rather than in code because platforms
| change them without notice: a limit change must be a config edit, never a
| code change and redeploy.
|
| *** EVERY NUMERIC LIMIT AND SCOPE NAME BELOW IS [VERIFY]. ***
|
| They are placeholders based on commonly-cited values and MUST be confirmed
| against current official provider documentation before the corresponding
| provider ships. Do not treat them as authoritative.
| See docs/05-SOCIAL-PROVIDERS.md §4.
|
*/

return [

    'refresh_lead_time' => (int) env('SOCIAL_REFRESH_LEAD_TIME', 86400),

    // Most connections one social:refresh-tokens pass will renew. Bounded so a
    // backlog cannot turn a single tick into an hour of provider calls.
    'refresh_batch_size' => (int) env('SOCIAL_REFRESH_BATCH_SIZE', 100),

    'oauth' => [
        'state_ttl' => 600,
        // Redirect URIs are exact-matched from config. An arbitrary
        // redirect_uri is never accepted from a request.
        'redirect_path' => '/oauth/{provider}/callback',
    ],

    /*
     | Provider-agnostic feature keys. A provider config that names a feature
     | outside this list is a typo, and the registry test fails on it.
     */
    'features' => [
        'text', 'images', 'carousel', 'video', 'reels', 'stories',
        'link', 'document', 'poll', 'first_comment',
        'native_scheduling', 'deletion', 'analytics', 'comments',
    ],

    /*
    |--------------------------------------------------------------------------
    | Meta Graph API
    |--------------------------------------------------------------------------
    |
    | Shared by the Facebook Page and Instagram Business adapters, which are the
    | same API with different node types.
    |
    | The VERSION is configuration rather than a constant because Meta retires
    | versions on a published schedule: pinning it in code turns a calendar
    | event into an outage, and moving it should be a deployment decision taken
    | after somebody has read the changelog.
    |
    */
    'meta' => [
        'graph_version' => env('META_GRAPH_VERSION', 'v25.0'),
        'timeout' => (int) env('META_TIMEOUT', 20),

        /*
         | Instagram processes an upload before it can be published. Bounded,
         | because a job that waits indefinitely on a queue belonging to
         | somebody else is a worker that never comes back. Exceeding the bound
         | is retryable and does not consume an attempt.
         */
        'container_poll_attempts' => (int) env('META_CONTAINER_POLL_ATTEMPTS', 8),
        'container_poll_seconds' => (int) env('META_CONTAINER_POLL_SECONDS', 3),
    ],

    'providers' => [

        'facebook' => [
            'name' => 'Facebook',
            'enabled' => true,
            'account_types' => ['page'],
            'page' => [
                'capabilities' => [
                    'text' => true, 'images' => true, 'carousel' => true,
                    'video' => true, 'reels' => true, 'stories' => false,
                    'link' => true, 'document' => false, 'poll' => false,
                    'first_comment' => true, 'native_scheduling' => true,
                    'deletion' => true, 'analytics' => true, 'comments' => true,
                ],
                'limits' => [
                    'text_max' => 63206,        // [VERIFY]
                    'images_max' => 10,         // [VERIFY]
                    'video_max_bytes' => null,  // [VERIFY]
                    'video_max_seconds' => null, // [VERIFY]
                ],
                /*
                 | VERIFIED 2026-09-05 against the Pages API posts reference.
                 | pages_manage_engagement is required for publishing a post as
                 | well, and the person must additionally hold CREATE_CONTENT
                 | on the Page itself -- which discoverAccounts() filters on,
                 | because a granted scope is not the same as a Page role.
                 */
                'required_scopes' => [
                    'pages_manage_posts',
                    'pages_manage_engagement',
                    'pages_read_engagement',
                ],
                'feature_scopes' => [
                    // VERIFIED 2026-09-05: the insights endpoints require
                    // read_insights, a Page access token, and the ANALYZE task
                    // on the Page. The scope alone is not enough.
                    'analytics' => ['read_insights'],
                ],
            ],
        ],

        'instagram' => [
            'name' => 'Instagram',
            'enabled' => true,
            // Reached through a linked Facebook Page rather than its own OAuth
            // flow, which is why discoverAccounts returns a mixed collection.
            'account_types' => ['ig_business'],
            'ig_business' => [
                'capabilities' => [
                    'text' => true, 'images' => true, 'carousel' => true,
                    'video' => true, 'reels' => true, 'stories' => true,
                    'link' => false, 'document' => false, 'poll' => false,
                    'first_comment' => true, 'native_scheduling' => false,
                    'deletion' => false, 'analytics' => true, 'comments' => true,
                ],
                'limits' => [
                    'text_max' => 2200,          // [VERIFY]
                    'images_max' => 10,          // VERIFIED 2026-09-05: up to 10 carousel items
                    'hashtags_max' => 30,        // [VERIFY]
                    'video_max_seconds' => 90,   // [VERIFY]
                    'aspect_ratio_min' => 0.8,   // [VERIFY]
                    'aspect_ratio_max' => 1.91,  // [VERIFY]
                ],
                /*
                 | VERIFIED 2026-09-05 against the Instagram content-publishing
                 | reference. instagram_basic is required alongside the publish
                 | scope: without it the account cannot even be discovered, so
                 | publishing alone leaves a destination nobody can select.
                 */
                'required_scopes' => ['instagram_basic', 'instagram_content_publish'],
                'feature_scopes' => [],
            ],
        ],

        'linkedin' => [
            'name' => 'LinkedIn',
            'enabled' => true,
            'account_types' => ['profile', 'organization'],
            'profile' => [
                'capabilities' => [
                    'text' => true, 'images' => true, 'carousel' => false,
                    'video' => true, 'reels' => false, 'stories' => false,
                    'link' => true, 'document' => true, 'poll' => false,
                    'first_comment' => true, 'native_scheduling' => false,
                    'deletion' => true, 'analytics' => false, 'comments' => true,
                ],
                'limits' => ['text_max' => 3000, 'images_max' => 9], // [VERIFY]
                'required_scopes' => ['w_member_social'],            // [VERIFY]
                'feature_scopes' => [],
            ],
            'organization' => [
                'capabilities' => [
                    'text' => true, 'images' => true, 'carousel' => false,
                    'video' => true, 'reels' => false, 'stories' => false,
                    'link' => true, 'document' => true, 'poll' => true,
                    'first_comment' => true, 'native_scheduling' => false,
                    'deletion' => true, 'analytics' => true, 'comments' => true,
                ],
                'limits' => ['text_max' => 3000, 'images_max' => 9], // [VERIFY]
                'required_scopes' => ['w_organization_social'],       // [VERIFY]
                'feature_scopes' => [],
            ],
        ],

        'x' => [
            'name' => 'X',
            // Disabled by default: write volume is tier-dependent and is a
            // COMMERCIAL dependency as much as a technical one. Confirm the
            // tier, quota and pricing before offering X in any plan.
            'enabled' => false,
            'account_types' => ['profile'],
            'profile' => [
                'capabilities' => [
                    'text' => true, 'images' => true, 'carousel' => false,
                    'video' => true, 'reels' => false, 'stories' => false,
                    'link' => true, 'document' => false, 'poll' => true,
                    'first_comment' => true, 'native_scheduling' => false,
                    'deletion' => true, 'analytics' => false, 'comments' => true,
                ],
                'limits' => ['text_max' => 280, 'images_max' => 4], // [VERIFY]
                'required_scopes' => ['tweet.write', 'users.read'],  // [VERIFY]
                'feature_scopes' => [],
            ],
        ],

        'youtube' => [
            'name' => 'YouTube',
            'enabled' => true,
            'account_types' => ['channel'],
            'channel' => [
                'capabilities' => [
                    'text' => false, 'images' => false, 'carousel' => false,
                    'video' => true, 'reels' => true, 'stories' => false,
                    'link' => false, 'document' => false, 'poll' => false,
                    'first_comment' => true, 'native_scheduling' => true,
                    'deletion' => true, 'analytics' => true, 'comments' => true,
                ],
                'limits' => [
                    'title_max' => 100,        // [VERIFY]
                    'description_max' => 5000, // [VERIFY]
                ],
                'required_scopes' => ['https://www.googleapis.com/auth/youtube.upload'], // [VERIFY]
                'feature_scopes' => [],
            ],
        ],

    ],

    /*
     | Outbound throttle, per (provider, credential). Jobs that cannot acquire
     | a token release themselves back to the queue with a delay rather than
     | spinning. All [VERIFY].
     */
    'rate_limits' => [
        'facebook' => ['per_hour' => 200],
        'instagram' => ['per_hour' => 100],
        'linkedin' => ['per_day' => 150],
        'x' => ['per_15_min' => 50],
        'youtube' => ['per_day' => 6],
    ],

];
