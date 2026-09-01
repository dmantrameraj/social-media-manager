<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Permission catalogue
    |--------------------------------------------------------------------------
    |
    | Code owns this list; a seeder syncs it into the permissions table. Adding
    | a permission is a code change, never a manual database edit.
    |
    | All authorization checks are against permissions. hasRole() must not
    | appear in application logic -- see docs/04-AUTH-RBAC.md §5.
    |
    */

    'guards' => [
        'tenant' => 'web',
        'portal' => 'customer',
    ],

    /*
     | Tenant permissions, grouped for the role-editor UI.
     */
    'tenant' => [

        'customers' => [
            'customers.view',
            'customers.view_all',
            'customers.create',
            'customers.update',
            'customers.delete',
            'customers.archive',
        ],

        'posts' => [
            'posts.view',
            'posts.create',
            'posts.update',
            'posts.delete',
            'posts.publish',
            'posts.approve_internal',
            'posts.schedule',
            'posts.retry',
            'posts.bulk_import',
        ],

        'media' => [
            'media.view',
            'media.upload',
            'media.update',
            'media.delete',
            'media.manage_folders',
        ],

        'social' => [
            'social_accounts.view',
            'social_accounts.connect',
            'social_accounts.disconnect',
            'social_accounts.assign',
            'social_credentials.manage',
        ],

        'ai' => [
            'ai.use',
            'ai.manage_brand_brain',
            'ai.view_usage',
        ],

        'team' => [
            'team.view',
            'team.invite',
            'team.update',
            'team.remove',
            'team.manage_roles',
        ],

        'portal_users' => [
            'portal_users.view',
            'portal_users.invite',
            'portal_users.remove',
        ],

        'analytics' => [
            'analytics.view',
            'reports.generate',
            'reports.share',
        ],

        'billing' => [
            'billing.view',
            'billing.manage',
            'billing.view_invoices',
        ],

        'settings' => [
            'settings.view',
            'settings.update',
            'branding.manage',
            'audit_logs.view',
        ],

    ],

    /*
     | Platform permissions. Super Admin only -- these are never assignable to a
     | tenant role, and the seeder refuses to attach them to one.
     */
    'platform' => [
        'platform.tenants.manage',
        'platform.plans.manage',
        'platform.subscriptions.manage',
        'platform.entitlements.override',
        'platform.credits.adjust',
        'platform.impersonate',
        'platform.feature_flags.manage',
        'platform.jobs.view',
        'platform.audit.view',
        'platform.announcements.manage',
    ],

    /*
     | Portal permissions (customer guard).
     */
    'portal' => [
        'portal.posts.view',
        'portal.posts.approve',
        'portal.posts.reject',
        'portal.posts.comment',
        'portal.reports.view',
    ],

    /*
    |--------------------------------------------------------------------------
    | Default role templates
    |--------------------------------------------------------------------------
    |
    | Seeded per tenant on creation. Tenants may edit their own roles; these
    | templates are the starting point, not a runtime constraint.
    |
    | '*' expands to every tenant permission.
    |
    */

    'roles' => [

        'Agency Owner' => ['*'],

        'Agency Admin' => [
            'except' => ['billing.manage', 'social_credentials.manage'],
        ],

        'Manager' => [
            'customers.view', 'customers.view_all', 'customers.update',
            'posts.view', 'posts.create', 'posts.update', 'posts.delete',
            'posts.publish', 'posts.approve_internal', 'posts.schedule', 'posts.retry',
            'media.view', 'media.upload', 'media.update', 'media.delete', 'media.manage_folders',
            'social_accounts.view', 'social_accounts.assign',
            'ai.use', 'ai.manage_brand_brain',
            'team.view',
            'portal_users.view', 'portal_users.invite',
        ],

        'Content Creator' => [
            'customers.view',
            'posts.view', 'posts.create', 'posts.update',
            'media.view', 'media.upload',
            'ai.use',
        ],

        'Designer' => [
            'customers.view',
            'posts.view',
            'media.view', 'media.upload', 'media.update', 'media.delete', 'media.manage_folders',
        ],

        'Approver' => [
            'customers.view',
            'posts.view', 'posts.update', 'posts.approve_internal',
        ],

        'Analyst' => [
            'customers.view',
            'posts.view',
            'analytics.view', 'reports.generate',
        ],

        'Client Viewer' => [
            'customers.view',
            'posts.view',
        ],

    ],

    /*
     | Portal role templates, assigned per brand in
     | customer_portal_user_customer.role.
     */
    'portal_roles' => [

        'Portal Approver' => [
            'portal.posts.view',
            'portal.posts.approve',
            'portal.posts.reject',
            'portal.posts.comment',
        ],

        'Portal Viewer' => [
            'portal.posts.view',
            'portal.reports.view',
        ],

    ],

];
