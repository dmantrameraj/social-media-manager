<?php

declare(strict_types=1);

use App\Domain\Billing\Entitlements\Enums\EntitlementType;

return [

    /*
    |--------------------------------------------------------------------------
    | Entitlement catalogue
    |--------------------------------------------------------------------------
    |
    | The single source of truth for what may be limited. plan_features.key and
    | subscription_overrides.key are validated against these keys, so a typo
    | cannot create a silently unenforced limit.
    |
    | Resolution order at runtime is:
    |     subscription_overrides -> plan_features -> the default below
    |
    | See docs/09-BILLING.md §3.
    |
    | 'usage' names the counter used by EntitlementResolver::currentUsage().
    | Null means the entitlement is a boolean switch with nothing to count.
    |
    */

    'keys' => [

        'brands.max' => [
            'type' => EntitlementType::Limit,
            'default' => 1,
            'usage' => 'brands',
            'label' => 'Customer brands',
        ],

        'social_accounts.max' => [
            'type' => EntitlementType::Limit,
            'default' => 2,
            'usage' => 'social_accounts',
            'label' => 'Connected social accounts',
        ],

        'team_members.max' => [
            'type' => EntitlementType::Limit,
            'default' => 1,
            'usage' => 'team_members',
            'label' => 'Team members',
        ],

        'portal_users.max' => [
            'type' => EntitlementType::Limit,
            'default' => 1,
            'usage' => 'portal_users',
            'label' => 'Client portal logins',
        ],

        'posts.scheduled_per_month' => [
            'type' => EntitlementType::Limit,
            'default' => 20,
            'usage' => 'posts_scheduled_this_period',
            'label' => 'Scheduled posts per month',
        ],

        'ai.credits_per_month' => [
            'type' => EntitlementType::Limit,
            'default' => 0,
            'usage' => 'ai_credits_consumed_this_period',
            'label' => 'AI credits per month',
        ],

        'storage.max_bytes' => [
            'type' => EntitlementType::Limit,
            'default' => 1_073_741_824, // 1 GiB
            'usage' => 'storage_bytes',
            'label' => 'Media storage',
        ],

        'customers.approval_workflow' => [
            'type' => EntitlementType::Boolean,
            'default' => true,
            'usage' => null,
            'label' => 'Client approval workflow',
        ],

        'ai.autopilot' => [
            'type' => EntitlementType::Boolean,
            'default' => false,
            'usage' => null,
            'label' => 'AI autopilot',
        ],

        'white_label.enabled' => [
            'type' => EntitlementType::Boolean,
            'default' => false,
            'usage' => null,
            'label' => 'White labelling',
        ],

        'analytics.enabled' => [
            'type' => EntitlementType::Boolean,
            'default' => false,
            'usage' => null,
            'label' => 'Analytics',
        ],

        'api.enabled' => [
            'type' => EntitlementType::Boolean,
            'default' => false,
            'usage' => null,
            'label' => 'API access',
        ],

        'support.priority' => [
            'type' => EntitlementType::Boolean,
            'default' => false,
            'usage' => null,
            'label' => 'Priority support',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    |
    | Resolved entitlements are cached per tenant per key. Invalidation is
    | explicit on plan, subscription and override changes -- we do not rely on
    | the TTL, because a stale entitlement either blocks a paying customer or
    | gives product away.
    |
    */

    'cache' => [
        'enabled' => env('ENTITLEMENT_CACHE_ENABLED', true),
        'ttl' => (int) env('ENTITLEMENT_CACHE_TTL', 3600),
        'prefix' => 'entitlements',
    ],

];
