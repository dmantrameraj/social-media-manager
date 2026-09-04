<?php

declare(strict_types=1);

namespace App\Domain\Social\Services;

use App\Domain\Audit\AuditLogger;
use App\Domain\Social\Enums\AccountHealth;
use App\Domain\Social\Enums\ConnectionStatus;
use App\Domain\Social\Exceptions\ProviderException;
use App\Domain\Social\Exceptions\UnknownProvider;
use App\Domain\Social\Models\SocialConnection;
use App\Domain\Social\ProviderRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Renews a grant before it expires.
 *
 * `SocialConnection::scopeNeedingRefresh()` was written, `refresh()` is on the
 * provider contract and every adapter implements it, and `refresh_lead_time`
 * has been in config since the social tables were created. Nothing called any
 * of it -- so a token reached its expiry and publishing simply began failing,
 * with no path back except an agency noticing and reconnecting by hand.
 *
 * The lead time is the point: refreshing a day BEFORE expiry means a failure
 * still leaves a working connection and a day to act, where refreshing at the
 * moment of expiry would mean every transient blip becomes an outage.
 */
final class RefreshConnectionService
{
    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return bool whether the grant now holds a fresh token
     */
    public function refresh(SocialConnection $connection): bool
    {
        try {
            $provider = $this->providers->for($connection->provider_key);
        } catch (UnknownProvider) {
            /*
             | An adapter that is not registered in this deployment. The grant
             | is not broken and must NOT be marked as needing reconnection --
             | telling an agency to re-authorise an account we simply cannot
             | reach would send them round a loop that cannot terminate.
             */
            Log::warning('No adapter registered to refresh this connection.', [
                'social_connection_id' => $connection->getKey(),
                'provider' => $connection->provider_key,
            ]);

            return false;
        }

        try {
            $tokens = $provider->refresh($connection);
        } catch (ProviderException $e) {
            $this->recordFailure($connection, $e);

            return false;
        }

        $connection->forceFill([
            'access_token' => $tokens->accessToken,
            /*
             | Some providers return a rolling refresh token and some return
             | none, meaning "keep using the one you have". Overwriting with
             | null in the second case would destroy the only thing that can
             | renew this grant again.
             */
            'refresh_token' => $tokens->refreshToken ?? $connection->refresh_token,
            'token_type' => $tokens->tokenType,
            'expires_at' => $tokens->expiresAt,
            'refresh_expires_at' => $tokens->refreshExpiresAt,
            'scopes' => $tokens->grantedScopes !== [] ? $tokens->grantedScopes : $connection->scopes,
            'status' => ConnectionStatus::Active->value,
            'last_refreshed_at' => now(),
            'last_error_code' => null,
        ])->save();

        // No token in the entry. SecretRedactor would strip one, but the safer
        // habit is not to hand it over at all.
        $this->audit->log(
            'social.connection_refreshed',
            $connection,
            newValues: ['expires_at' => $tokens->expiresAt?->toIso8601String()],
            tenantId: $connection->tenant_id,
        );

        return true;
    }

    /**
     * A failed renewal is not automatically a dead connection.
     */
    private function recordFailure(SocialConnection $connection, ProviderException $e): void
    {
        /*
         | The distinction the whole command turns on. A network blip or a
         | platform 500 is retried on the next tick and must not tell an agency
         | to re-authorise; only the provider actually rejecting the grant
         | means a person has to do something.
         |
         | requiresReconnect() already draws that line -- AuthExpired and
         | Permission -- and had no caller until now.
         */
        if (! $e->requiresReconnect()) {
            $connection->forceFill(['last_error_code' => $e->errorClass->value])->save();

            Log::info('A token refresh failed and will be retried.', [
                'social_connection_id' => $connection->getKey(),
                'provider' => $connection->provider_key,
                'error_class' => $e->errorClass->value,
            ]);

            return;
        }

        DB::transaction(function () use ($connection, $e): void {
            $connection->forceFill([
                'status' => ConnectionStatus::NeedsReconnect->value,
                'last_error_code' => $e->errorClass->value,
            ])->save();

            /*
             | The accounts behind it are marked too. Health is what the
             | connected-accounts screen reads, and a grant that needs
             | re-authorising while its destinations still look healthy is how
             | somebody schedules a week of posts that cannot go out.
             |
             | Status is left alone: these accounts are not disconnected, and
             | freeing their plan seats here would let a tenant quietly exceed
             | the limit while a reconnect is pending.
             |
             | Reached through the relation rather than acrossTenants(). The
             | caller re-establishes tenant context per connection, exactly as
             | PublishPostTarget does, so every read here goes through the
             | ordinary scope and a bug in this file cannot touch another
             | agency's rows.
             */
            $connection->accounts()
                ->update([
                    'health' => AccountHealth::Failed->value,
                    'last_error_code' => $e->errorClass->value,
                    'last_error_at' => now(),
                ]);
        });

        $this->audit->log(
            'social.connection_needs_reconnect',
            $connection,
            newValues: [
                'provider' => $connection->provider_key,
                'error_class' => $e->errorClass->value,
            ],
            tenantId: $connection->tenant_id,
        );

        Log::warning('A connection now needs re-authorising.', [
            'social_connection_id' => $connection->getKey(),
            'provider' => $connection->provider_key,
            'error_class' => $e->errorClass->value,
        ]);
    }
}
