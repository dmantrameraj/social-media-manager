<?php

declare(strict_types=1);

namespace App\Domain\Social\OAuth;

use App\Domain\Social\Exceptions\OAuthStateInvalid;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Issues and consumes OAuth state.
 *
 * Four properties, all enforced here and asserted by tests:
 *   - single use  -- consumption is an atomic conditional UPDATE
 *   - bound       -- to a tenant AND a user, not just a session
 *   - expiring    -- ten minutes by default
 *   - unguessable -- 256 bits of randomness, stored only as a hash
 *
 * See docs/05-SOCIAL-PROVIDERS.md §7.
 */
final class OAuthStateService
{
    /**
     * @param  list<string>  $scopes
     * @return array{state: string, context: OAuthContext}
     */
    public function issue(
        int $tenantId,
        int $userId,
        string $providerKey,
        array $scopes = [],
        ?int $customerId = null,
        ?int $credentialId = null,
        ?string $redirectTo = null,
        bool $usePkce = false,
    ): array {
        $state = bin2hex(random_bytes(32));
        $verifier = $usePkce ? Str::random(96) : null;

        DB::table('oauth_states')->insert([
            // Only the hash is stored: a database read must not yield a
            // usable state value.
            'state_hash' => hash('sha256', $state),
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'customer_id' => $customerId,
            'provider_key' => $providerKey,
            'social_app_credential_id' => $credentialId,
            'code_verifier' => $verifier !== null ? encrypt($verifier) : null,
            'redirect_to' => $this->sanitiseRedirect($redirectTo),
            'expires_at' => now()->addSeconds((int) config('social.oauth.state_ttl', 600)),
            'created_at' => now(),
        ]);

        return [
            'state' => $state,
            'context' => new OAuthContext(
                tenantId: $tenantId,
                userId: $userId,
                providerKey: $providerKey,
                redirectUri: $this->redirectUri($providerKey),
                state: $state,
                scopes: $scopes,
                codeVerifier: $verifier,
                customerId: $customerId,
            ),
        ];
    }

    /**
     * Consume a state exactly once.
     *
     * @throws OAuthStateInvalid
     */
    public function consume(string $state, string $providerKey, int $userId): OAuthContext
    {
        $hash = hash('sha256', $state);

        $row = DB::table('oauth_states')->where('state_hash', $hash)->first();

        if ($row === null) {
            throw new OAuthStateInvalid('This authorisation link is not valid.');
        }

        // Atomic single-use claim. Two callbacks racing the same state must
        // not both succeed -- that is a replay.
        $claimed = DB::table('oauth_states')
            ->where('state_hash', $hash)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        if ($claimed !== 1) {
            throw new OAuthStateInvalid('This authorisation link has already been used.');
        }

        if ($row->expires_at !== null && now()->greaterThan($row->expires_at)) {
            throw new OAuthStateInvalid('This authorisation link has expired.');
        }

        // Bound to the provider it was issued for: a state minted for one
        // network must not complete a connection on another.
        if (! hash_equals((string) $row->provider_key, $providerKey)) {
            throw new OAuthStateInvalid('This authorisation link does not match the provider.');
        }

        // Bound to the user, not merely to a session.
        if ((int) $row->user_id !== $userId) {
            throw new OAuthStateInvalid('This authorisation link was issued to a different user.');
        }

        return new OAuthContext(
            tenantId: (int) $row->tenant_id,
            userId: (int) $row->user_id,
            providerKey: $providerKey,
            redirectUri: $this->redirectUri($providerKey),
            state: $state,
            codeVerifier: $row->code_verifier !== null ? decrypt($row->code_verifier) : null,
            customerId: $row->customer_id !== null ? (int) $row->customer_id : null,
        );
    }

    public function pruneExpired(): int
    {
        return DB::table('oauth_states')
            ->where('expires_at', '<', now()->subDay())
            ->delete();
    }

    /**
     * Built from config and exact-matched by the provider. Never taken from a
     * request.
     */
    public function redirectUri(string $providerKey): string
    {
        $path = str_replace(
            '{provider}',
            $providerKey,
            (string) config('social.oauth.redirect_path', '/oauth/{provider}/callback'),
        );

        return rtrim((string) config('app.url'), '/').$path;
    }

    /**
     * The post-connect landing page.
     *
     * Only a relative path on our own host is accepted. An absolute URL here
     * would be an open redirect, which is a phishing primitive: an attacker
     * could send a victim through a genuine OAuth flow that lands on a site
     * they control.
     */
    private function sanitiseRedirect(?string $redirectTo): ?string
    {
        if ($redirectTo === null || $redirectTo === '') {
            return null;
        }

        // Reject anything with a scheme or host, and protocol-relative URLs.
        if (! str_starts_with($redirectTo, '/') || str_starts_with($redirectTo, '//')) {
            return null;
        }

        if (str_contains($redirectTo, "\n") || str_contains($redirectTo, "\r")) {
            return null;
        }

        return Str::limit($redirectTo, 500, '');
    }
}
