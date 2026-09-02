<?php

declare(strict_types=1);

return [

    'default' => env('AI_DEFAULT_PROVIDER', 'anthropic'),

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    |
    | Model IDs live here, never in feature classes: models are deprecated on a
    | schedule, and a hardcoded ID becomes an outage.
    |
    */

    'providers' => [

        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'model' => env('ANTHROPIC_MODEL', 'claude-opus-5'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Credit costs
    |--------------------------------------------------------------------------
    |
    | A flat cost per feature is predictable for the customer. Token overage
    | protects margin on unusually long outputs without making the common case
    | unpredictable.
    |
    | Credits are an internal unit decoupled from vendor pricing, so changing
    | model does not change what a customer was sold.
    |
    */

    'costs' => [
        'caption' => 1,
        'hashtags' => 1,
        'ideas' => 2,
        'rewrite' => 1,
        'tone' => 1,
        'translate' => 1,
        'platform_adaptation' => 1,
        'reel_script' => 3,
        'youtube_title' => 1,
        'youtube_description' => 2,
        'blog_to_social' => 3,
        'monthly_plan' => 25,
    ],

    'token_overage' => [
        'enabled' => env('AI_TOKEN_OVERAGE', true),
        'tokens_per_credit' => 2000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Reservations
    |--------------------------------------------------------------------------
    |
    | A reservation older than this is swept and released, so a worker that
    | died mid-generation cannot strand a tenant's credits.
    |
    */

    'reservation_ttl' => (int) env('AI_RESERVATION_TTL', 900),

    /*
    |--------------------------------------------------------------------------
    | Generation logging
    |--------------------------------------------------------------------------
    |
    | Request and response snapshots contain customer business content, so they
    | are purged on a schedule rather than kept indefinitely.
    |
    */

    'snapshot_retention_days' => (int) env('AI_SNAPSHOT_RETENTION_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Brand Brain
    |--------------------------------------------------------------------------
    |
    | Per-field caps. Brand Brain content is USER-SUPPLIED and is interpolated
    | into a system prompt, so it is treated as untrusted data: capped, clearly
    | delimited, and labelled as data rather than instructions.
    | See docs/08-AI-ARCHITECTURE.md §3.
    |
    */

    'brand_brain' => [
        'max_field_length' => 2000,
        'max_list_items' => 25,

        // Fields that meaningfully raise output quality when filled in. Drives
        // the completeness score shown in the UI, so users understand why thin
        // input yields thin output.
        'completeness_fields' => [
            'business_description', 'industry', 'target_audience',
            'brand_tone', 'products', 'services', 'usps',
            'preferred_keywords', 'content_themes', 'ctas',
        ],
    ],

];
