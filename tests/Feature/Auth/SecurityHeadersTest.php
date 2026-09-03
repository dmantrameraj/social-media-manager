<?php

declare(strict_types=1);

use Illuminate\Support\Str;

/*
 | docs/10-SECURITY.md §7 has specified these response headers since Phase 0.
 | Nothing set any of them until now.
 */

it('sets the static headers on every response', function (): void {
    $response = $this->get(route('login'));

    $response
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

    expect($response->headers->get('Permissions-Policy'))->toContain('camera=()');
});

it('reaches the portal surface too', function (): void {
    // Appended to the web group, so agency, portal and admin all share it --
    // a header set on only the routes someone remembered is not a policy.
    $this->get(route('portal.login'))->assertHeader('X-Frame-Options', 'DENY');
});

it('sends CSP as report-only by default', function (): void {
    /*
     | Content-Security-Policy-Report-Only and Content-Security-Policy are
     | different header names. Sending the report-only name is what keeps a
     | violation from being enforced, so the header that is ABSENT matters as
     | much as the one present.
     */
    $response = $this->get(route('login'));

    $response->assertHeader('Content-Security-Policy-Report-Only');

    expect($response->headers->has('Content-Security-Policy'))->toBeFalse();
});

it('enforces CSP once configured to', function (): void {
    config(['security.csp_report_only' => false]);

    $response = $this->get(route('login'));

    $response->assertHeader('Content-Security-Policy');

    expect($response->headers->has('Content-Security-Policy-Report-Only'))->toBeFalse();
});

it('denies framing by default in the CSP too', function (): void {
    $response = $this->get(route('login'));

    expect($response->headers->get('Content-Security-Policy-Report-Only'))
        ->toContain("frame-ancestors 'none'");
});

/**
 * The same login URL, forced to https.
 *
 * withServerVariables(['HTTPS' => 'on']) is not enough on its own:
 * Symfony's Request::create() derives HTTPS from the URL's OWN scheme after
 * merging server variables, and unsets whatever was passed if that scheme
 * is not https. route() builds from APP_URL, which is http in testing, so
 * the URL itself has to carry the scheme for Request::isSecure() to see it.
 */
function secureLoginUrl(): string
{
    return Str::replaceFirst('http://', 'https://', route('login'));
}

it('does not send HSTS by default even over a secure request', function (): void {
    // Off until a deliberate deploy-time decision, per config/security.php --
    // turning it on by accident is not reversible for anyone who already
    // has the header cached.
    $response = $this->get(secureLoginUrl());

    expect($response->headers->has('Strict-Transport-Security'))->toBeFalse();
});

it('sends HSTS only once enabled and only over a secure connection', function (): void {
    config(['security.hsts.enabled' => true, 'security.hsts.max_age' => 63072000]);

    $insecure = $this->get(route('login'));
    expect($insecure->headers->has('Strict-Transport-Security'))->toBeFalse();

    $secure = $this->get(secureLoginUrl());
    $secure->assertHeader('Strict-Transport-Security', 'max-age=63072000');
});
