<?php

declare(strict_types=1);

namespace App\Domain\Social\Services;

use App\Domain\Social\Models\SocialAppCredential;

/**
 * Which developer app an agency's OAuth grant should run through.
 *
 * The product's stated differentiator is that an agency may bring its own
 * provider credentials, so that platform-wide API quota is not a ceiling on
 * how many clients they can serve. The table, the encrypted casts, the
 * permission and even oauth_states.social_app_credential_id have existed since
 * Phase 2; nothing chose a credential, so every grant fell through to whatever
 * the platform had configured, and the differentiator was a schema comment.
 *
 * Returning null is a real answer, not a failure: it means "use the platform's
 * own app", which is the correct behaviour for an agency that has not supplied
 * one and the reason a new tenant can connect anything at all.
 */
final class ResolveAppCredentialService
{
    public function for(int $tenantId, string $providerKey): ?SocialAppCredential
    {
        return SocialAppCredential::query()
            ->where('tenant_id', $tenantId)
            ->where('provider_key', $providerKey)
            ->where('is_active', true)
            /*
             | Newest wins when an agency holds more than one. They are meant
             | to keep a single active app per network -- the screen says so --
             | but ordering makes the outcome deterministic rather than
             | dependent on row order, the same reasoning EntitlementResolver
             | applies to a tenant with two subscriptions.
             */
            ->orderByDesc('id')
            ->first();
    }
}
