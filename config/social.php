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
                'required_scopes' => ['pages_manage_posts', 'pages_read_engagement'], // [VERIFY]
                'feature_scopes' => [
                    'analytics' => ['read_insights'],   // [VERIFY]
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
                    'images_max' => 10,          // [VERIFY]
                    'hashtags_max' => 30,        // [VERIFY]
                    'video_max_seconds' => 90,   // [VERIFY]
                    'aspect_ratio_min' => 0.8,   // [VERIFY]
                    'aspect_ratio_max' => 1.91,  // [VERIFY]
                ],
                'required_scopes' => ['instagram_content_publish'], // [VERIFY]
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
