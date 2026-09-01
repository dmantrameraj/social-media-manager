<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Lifecycle windows
    |--------------------------------------------------------------------------
    */

    'trial_days' => (int) env('BILLING_TRIAL_DAYS', 7),

    'grace_days' => (int) env('BILLING_GRACE_DAYS', 7),

    /*
     | Whether scheduled posts continue to go out during the grace period.
     |
     | Defaults to TRUE deliberately. Cutting off a client's scheduled posts
     | because an agency's card expired damages the agency's relationship with
     | their own customer, and grace exists precisely to absorb that. This is a
     | business decision, not a technical one -- see docs/09-BILLING.md §5.
     */
    'publish_during_grace' => (bool) env('BILLING_PUBLISH_DURING_GRACE', true),

    /*
    |--------------------------------------------------------------------------
    | Money
    |--------------------------------------------------------------------------
    |
    | All amounts are integer minor units plus a currency code. No floats.
    |
    */

    'currency' => env('BILLING_CURRENCY', 'INR'),

    'default_gateway' => env('BILLING_GATEWAY', 'razorpay'),

    /*
    |--------------------------------------------------------------------------
    | Invoice numbering
    |--------------------------------------------------------------------------
    |
    | Sequential per financial year, allocated under a row lock. AUTO_INCREMENT
    | is not used: it gaps on rollback, and accounting does not tolerate gaps.
    |
    */

    'invoice' => [
        'prefix' => env('BILLING_INVOICE_PREFIX', 'INV'),
        'pad_length' => 6,
        'financial_year_start_month' => 4,
    ],

    /*
    |--------------------------------------------------------------------------
    | Entitlement cache
    |--------------------------------------------------------------------------
    */

    'entitlement_cache_ttl' => (int) env('ENTITLEMENT_CACHE_TTL', 3600),

];
