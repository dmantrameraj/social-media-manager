<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Tenant lifecycle
    |--------------------------------------------------------------------------
    |
    | Windows are expressed in days. See docs/03-TENANCY.md §9 for the state
    | table these values drive.
    |
    */

    'trial_days' => (int) env('TENANCY_TRIAL_DAYS', 7),

    'grace_days' => (int) env('TENANCY_GRACE_DAYS', 7),

    /*
     | How long a cancelled tenant's data is retained before the anonymisation
     | job may touch it. Never shorten this without a documented decision --
     | see docs/10-SECURITY.md §9.
     */
    'retention_days' => (int) env('TENANCY_RETENTION_DAYS', 60),

    /*
     | Warning emails sent this many days before the purge runs.
     */
    'purge_warning_days' => [30, 7],

    /*
     | How long a team invitation stays usable. Short enough that a forwarded
     | or leaked link stops working, long enough to survive a holiday.
     */
    'invitation_expiry_days' => (int) env('TENANCY_INVITATION_EXPIRY_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | Reserved slugs
    |--------------------------------------------------------------------------
    |
    | Slugs a tenant may not claim. These would collide with first-party routes
    | or subdomains, or allow a tenant to impersonate the platform.
    |
    */

    'reserved_slugs' => [
        'admin', 'api', 'app', 'assets', 'auth', 'billing', 'blog', 'cdn',
        'dashboard', 'docs', 'help', 'login', 'mail', 'oauth', 'portal',
        'public', 'register', 'root', 'signup', 'static', 'status', 'support',
        'system', 'test', 'webhooks', 'www',
    ],

    /*
    |--------------------------------------------------------------------------
    | Context resolution
    |--------------------------------------------------------------------------
    |
    | Ordered list of strategies used to resolve the active tenant. A tenant id
    | supplied in a request body, query string or header is NEVER a strategy --
    | see docs/03-TENANCY.md §3.
    |
    */

    'resolution' => [
        'session_key' => 'tenant_id',
        'strategies' => ['session', 'sole_membership'],
        // 'host' is added in Phase 8 when custom domains ship.
    ],

    /*
    |--------------------------------------------------------------------------
    | Scope bypass allow-list
    |--------------------------------------------------------------------------
    |
    | Namespaces permitted to call Model::acrossTenants(). Anything outside this
    | list is a bug and is caught by the architecture test.
    |
    */

    'scope_bypass_namespaces' => [
        'App\\Domain\\Platform',
        'App\\Domain\\Tenancy\\Services',
        'App\\Http\\Controllers\\Admin',
        'App\\Http\\Livewire\\Admin',
        'App\\Console\\Commands',
        'App\\Jobs',
        // Queue workers run with no request and therefore no tenant
        // context; the ids they act on were written by this
        // application, never supplied by a user.
        'App\\Domain\\Media\\Jobs',

        /*
         | Scheduled sweepers and queue workers. Each runs on a timer or a
         | worker rather than a request, so there is no session to resolve a
         | tenant from and the work is cross-tenant by definition.
         |
         | These were bypassing the scope before they appeared here: the
         | list was never enforced, so it recorded intent rather than fact.
         | ScopeBypassTest now fails if a namespace bypasses without being
         | listed, which is what makes this an allow-list.
         */
        'App\\Domain\\AI\\Services',
        'App\\Domain\\Billing\\Subscriptions',
        'App\\Domain\\Publishing\\Services',
    ],

];
