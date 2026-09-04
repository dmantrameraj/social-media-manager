<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Redacted attributes
    |--------------------------------------------------------------------------
    |
    | Any attribute whose name matches one of these (case-insensitive substring)
    | is replaced with the placeholder before an audit row is written.
    |
    | A secret recoverable from the audit trail is a secret that leaked. This
    | list is deliberately broad: over-redacting costs a little forensic detail,
    | under-redacting costs a credential. See docs/10-SECURITY.md §10.
    |
    */

    'redacted_attributes' => [
        'password',
        'secret',
        'token',
        'access_token',
        'refresh_token',
        'client_secret',
        'client_id',
        'api_key',
        'apikey',
        'recovery_code',
        'two_factor',
        'signature',
        'authorization',
        'credit_card',
        'card_number',
        'cvv',
        'private_key',
        'code_verifier',
        'remember_token',
    ],

    'placeholder' => '[redacted]',

    /*
    |--------------------------------------------------------------------------
    | Value size cap
    |--------------------------------------------------------------------------
    |
    | Long values are truncated rather than stored whole. An audit log is a
    | record of WHAT changed, not a second copy of the content.
    |
    */

    'max_value_length' => 2000,

    /*
    |--------------------------------------------------------------------------
    | Attributes never worth recording
    |--------------------------------------------------------------------------
    |
    | Excluded because they change on almost every write and would bury the
    | changes that matter.
    |
    */

    'ignored_attributes' => [
        'updated_at',
        'created_at',
        'last_login_at',
        'last_login_ip',
    ],

    /*
    |--------------------------------------------------------------------------
    | Account activity shown to a user
    |--------------------------------------------------------------------------
    |
    | How many of their own authentication events a user sees on the security
    | screen. Enough to recognise a pattern, few enough that the query is cheap
    | on an account with years of history behind it.
    |
    */

    'login_history_shown' => (int) env('AUDIT_LOGIN_HISTORY_SHOWN', 20),

];
