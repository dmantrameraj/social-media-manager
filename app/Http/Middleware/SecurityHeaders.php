<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The response headers docs/10-SECURITY.md §7 has specified since Phase 0 and
 * nothing set until now: X-Content-Type-Options, X-Frame-Options,
 * Referrer-Policy, a minimal Permissions-Policy, and CSP.
 *
 * Applied on the response side only -- nothing here inspects the request --
 * so it is safe to append after everything else in the `web` group runs.
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Stops a browser guessing a response into a type the server did not
        // declare -- the concrete case being an "image" upload a browser
        // decides is HTML and executes in this origin.
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // No framing at all, on any origin including this one. Nothing in the
        // product is designed to be embedded, so there is no case where
        // allowing it costs less than a clickjacking surface it opens.
        $response->headers->set('X-Frame-Options', 'DENY');

        // Sends the full URL cross-origin only to same-origin destinations;
        // cross-origin gets the origin alone. A post's URL slug or an
        // approval token in a query string must not leak to a link a page
        // merely embeds -- an ad, a font host, a broken preview image.
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Off for every browser capability this product has no use for.
        // Empty allowlist, not an omitted directive: an omitted one defaults
        // to "this origin", which is a grant nobody decided to make.
        $response->headers->set(
            'Permissions-Policy',
            'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
        );

        $response->headers->set($this->cspHeaderName(), $this->cspPolicy());

        if ($this->shouldSendHsts($request)) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age='.(int) config('security.hsts.max_age', 31536000),
            );
        }

        return $response;
    }

    /**
     * Content-Security-Policy-Report-Only and Content-Security-Policy are
     * different header names, not one header with a flag -- sending the
     * report-only NAME is what keeps a violation from being enforced.
     */
    private function cspHeaderName(): string
    {
        return config('security.csp_report_only', true)
            ? 'Content-Security-Policy-Report-Only'
            : 'Content-Security-Policy';
    }

    private function cspPolicy(): string
    {
        $directives = (array) config('security.csp', []);

        $parts = [];

        foreach ($directives as $directive => $sources) {
            $parts[] = $directive.' '.implode(' ', (array) $sources);
        }

        return implode('; ', $parts);
    }

    /**
     * Only when actually enabled AND the connection is actually secure.
     *
     * Sending it over plain HTTP is inert in every browser, but sending it at
     * all should still describe a decision that was made, not a header that
     * fires by accident the first time a request happens to arrive over TLS
     * before hosting is confirmed stable -- see config/security.php.
     */
    private function shouldSendHsts(Request $request): bool
    {
        return (bool) config('security.hsts.enabled', false) && $request->secure();
    }
}
