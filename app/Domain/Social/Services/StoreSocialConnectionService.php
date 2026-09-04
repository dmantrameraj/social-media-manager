<?php

declare(strict_types=1);

namespace App\Domain\Social\Services;

use App\Domain\Audit\AuditLogger;
use App\Domain\Social\DTO\DiscoveredAccount;
use App\Domain\Social\DTO\TokenSet;
use App\Domain\Social\Enums\AccountHealth;
use App\Domain\Social\Enums\AccountStatus;
use App\Domain\Social\Enums\ConnectionStatus;
use App\Domain\Social\Models\SocialAccount;
use App\Domain\Social\Models\SocialConnection;
use App\Domain\Social\OAuth\OAuthContext;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Persists the result of a completed OAuth exchange.
 *
 * The OAuth machinery was written and had no callers: OAuthStateService could
 * issue and consume state, the provider contract could exchange a code, and
 * nothing joined the two to a stored connection. There was no way to connect
 * an account at all.
 *
 * Connections and accounts stay separate tables because one Meta grant
 * commonly yields several Pages plus their linked Instagram accounts. The
 * grant is the credential; the accounts are the destinations.
 */
final class StoreSocialConnectionService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Store or refresh the grant itself.
     *
     * Keyed on (tenant, provider, external user) -- the same unique the table
     * carries -- so reconnecting an account that already exists updates the
     * tokens in place rather than creating a second row that publishing would
     * have to choose between.
     */
    public function storeConnection(
        Tenant $tenant,
        OAuthContext $context,
        TokenSet $tokens,
    ): SocialConnection {
        return DB::transaction(function () use ($tenant, $context, $tokens): SocialConnection {
            $connection = SocialConnection::query()
                ->where('provider_key', $context->providerKey)
                ->where('external_user_id', $tokens->externalUserId)
                ->first() ?? new SocialConnection;

            $existing = $connection->exists;

            $connection->forceFill([
                'tenant_id' => $tenant->getKey(),
                'customer_id' => $context->customerId,
                'provider_key' => $context->providerKey,
                'external_user_id' => $tokens->externalUserId,
                'name' => $tokens->name,
                'email' => $tokens->email,
                /*
                 | What the provider ACTUALLY granted, not what was asked for.
                 | Users can decline individual scopes, and ProviderRegistry
                 | narrows capabilities from this -- recording the request
                 | would advertise abilities the grant does not carry.
                 */
                'scopes' => $tokens->grantedScopes,
                'access_token' => $tokens->accessToken,
                'refresh_token' => $tokens->refreshToken,
                'token_type' => $tokens->tokenType,
                'expires_at' => $tokens->expiresAt,
                'refresh_expires_at' => $tokens->refreshExpiresAt,
                'status' => ConnectionStatus::Active->value,
                'last_refreshed_at' => now(),
                'last_error_code' => null,
                'connected_by_user_id' => $context->userId,
            ]);

            if (! $connection->exists) {
                $connection->ulid = (string) Str::ulid();
            }

            $connection->save();

            /*
             | The token is never in the audit entry -- SecretRedactor would
             | strip it, but the safer habit is not to hand it over. Who
             | connected what, and which scopes were granted, is what a later
             | "why can this not publish?" needs.
             */
            $this->audit->log(
                $existing ? 'social.connection_reconnected' : 'social.connection_created',
                $connection,
                newValues: [
                    'provider' => $context->providerKey,
                    'granted_scopes' => $tokens->grantedScopes,
                ],
                tenantId: $tenant->getKey(),
            );

            return $connection;
        });
    }

    /**
     * Attach the destinations somebody chose to a brand.
     *
     * @param  list<DiscoveredAccount>  $accounts
     * @return int how many were stored
     */
    public function storeAccounts(
        SocialConnection $connection,
        int $customerId,
        array $accounts,
    ): int {
        $stored = 0;

        foreach ($accounts as $account) {
            DB::transaction(function () use ($connection, $customerId, $account, &$stored): void {
                $model = SocialAccount::query()
                    ->where('provider_key', $connection->provider_key)
                    ->where('external_id', $account->externalId)
                    ->first() ?? new SocialAccount;

                $model->forceFill([
                    'tenant_id' => $connection->tenant_id,
                    'customer_id' => $customerId,
                    'social_connection_id' => $connection->getKey(),
                    'provider_key' => $connection->provider_key,
                    'account_type' => $account->type->value,
                    'external_id' => $account->externalId,
                    'name' => $account->name,
                    'username' => $account->username,
                    'avatar_url' => $account->avatarUrl,
                    /*
                     | A Page token, where the provider issues one. It differs
                     | from the user token that discovered the Page and is what
                     | publishing actually sends, so storing the user token here
                     | would produce a connection that looks healthy and fails
                     | at publish time.
                     */
                    'page_access_token' => $account->pageAccessToken,
                    'scopes' => $account->scopes,
                    'status' => AccountStatus::Active->value,
                    'health' => AccountHealth::Healthy->value,
                    'last_error_code' => null,
                    'last_error_at' => null,
                    'meta' => $account->meta,
                ]);

                if (! $model->exists) {
                    $model->ulid = (string) Str::ulid();
                }

                $model->save();
                $stored++;
            });
        }

        if ($stored > 0) {
            $this->audit->log(
                'social.accounts_connected',
                $connection,
                newValues: ['customer_id' => $customerId, 'count' => $stored],
                tenantId: $connection->tenant_id,
            );
        }

        return $stored;
    }
}
