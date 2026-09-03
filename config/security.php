<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Content-Security-Policy
    |--------------------------------------------------------------------------
    |
    | docs/10-SECURITY.md §7 calls for CSP "report-only first, then enforcing".
    | Report-only sends Content-Security-Policy-Report-Only instead of
    | Content-Security-Policy: violations are visible in the browser console,
    | nothing is ever blocked. That is the right default for a first rollout --
    | switching straight to enforcing on a policy nobody has watched against
    | real traffic risks breaking a page nobody thought to check.
    |
    | style-src allows 'unsafe-inline' because four call sites currently need
    | it: the per-tenant brand colour set via style="background-color: ..." in
    | three layouts, and the profile-completeness bar's inline width. Both are
    | values computed per render, which a static stylesheet cannot express.
    | Tightening this to a nonce is follow-up work for when the policy moves to
    | enforcing -- not something to guess at while nothing is watching whether
    | it breaks the branding.
    |
    */

    'csp_report_only' => (bool) env('SECURITY_CSP_REPORT_ONLY', true),

    'csp' => [
        'default-src' => ["'self'"],
        'script-src' => ["'self'"],
        // See the note above: 'unsafe-inline' covers real, dynamic call
        // sites, not a shortcut taken to avoid finding them.
        'style-src' => ["'self'", "'unsafe-inline'"],
        'img-src' => ["'self'", 'data:'],
        'font-src' => ["'self'", 'data:'],
        'connect-src' => ["'self'"],
        // The CSP companion to X-Frame-Options: DENY below -- older browsers
        // that ignore frame-ancestors still get the protection from the
        // header, newer ones get the more expressive directive.
        'frame-ancestors' => ["'none'"],
        'base-uri' => ["'self'"],
        'form-action' => ["'self'"],
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Strict Transport Security
    |--------------------------------------------------------------------------
    |
    | Off by default and deliberately so. docs/10-SECURITY.md §7: "HSTS with a
    | 1-year max-age once the certificate is stable" -- HSTS is a promise a
    | browser holds onto for the full max-age with no way for the server to
    | retract it early, so turning it on before hosting is confirmed stable
    | can lock real visitors out of the site over a certificate problem that
    | would otherwise have been a five-minute fix.
    |
    | Enable by setting SECURITY_HSTS=true once that judgement has actually
    | been made, not as part of a deploy.
    |
    */

    'hsts' => [
        'enabled' => (bool) env('SECURITY_HSTS', false),
        'max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31536000),
    ],

];
