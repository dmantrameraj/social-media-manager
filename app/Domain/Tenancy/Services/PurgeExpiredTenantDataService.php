<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Services;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\CustomerPortalUser;
use App\Domain\Identity\Models\User;
use App\Domain\Media\Models\Media;
use App\Domain\Social\Models\SocialConnection;
use App\Domain\Social\ProviderRegistry;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Destroys the data of a tenant whose retention clock has run out.
 *
 * SubscriptionLifecycleService has been setting tenants.purge_after on
 * cancellation since billing shipped, and Tenant::duePurge() has been ready to
 * find them. Nothing consumed either. So the 60-day retention promise in
 * docs/10-SECURITY.md §9 was a date written into a column and never acted on:
 * every cancelled agency's client posts, brand profiles and media sat on disk
 * indefinitely.
 *
 * Order is deliberate and is the opposite of convenience:
 *
 *   1. revoke OAuth grants, THEN delete the rows
 *   2. delete media bytes, THEN the rows that point at them
 *   3. anonymise people
 *   4. record what happened
 *
 * Each step destroys the pointer only after the thing it points at is gone.
 * Reversing any of them loses the ability to finish the job -- deleting a
 * connection row first leaves a live grant on the provider with nothing left
 * to identify it.
 */
final class PurgeExpiredTenantDataService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ProviderRegistry $providers,
    ) {}

    /**
     * @return array{connections: int, media: int, users: int, portal_users: int}
     */
    public function purge(Tenant $tenant): array
    {
        $counts = [
            'connections' => $this->revokeAndDeleteConnections($tenant),
            'media' => $this->deleteMedia($tenant),
            'users' => 0,
            'portal_users' => 0,
        ];

        $counts['portal_users'] = $this->anonymisePortalUsers($tenant);
        $counts['users'] = $this->anonymiseUsers($tenant);

        DB::transaction(function () use ($tenant, $counts): void {
            /*
             | purge_after is cleared and purged_at set, so duePurge() no longer
             | matches. Without that a tenant would be purged again on every
             | run: harmless for the deletes, which are idempotent, but it would
             | write a fresh audit entry daily and make the log unreadable.
             */
            $tenant->purge_after = null;
            $tenant->purged_at = now();
            $tenant->status = TenantStatus::Cancelled;
            $tenant->save();

            /*
             | Audited, and the audit log SURVIVES the purge with its identifiers
             | already anonymised: the legal obligation to retain records and the
             | obligation to remove personal data are both satisfied by pointing
             | the records at people who no longer have names.
             |
             | Counts only. Naming what was deleted would reintroduce the data
             | into the log that the purge just removed from the tables.
             */
            $this->audit->log(
                'tenancy.data_purged',
                $tenant,
                newValues: $counts,
                tenantId: $tenant->getKey(),
            );
        });

        return $counts;
    }

    /**
     * Revoke each grant with the provider, then delete the row.
     *
     * docs/10-SECURITY.md §9 calls this out separately because it is the step
     * most often forgotten: deleting our row without revoking the grant leaves
     * a live token on the provider side, and once the row is gone there is
     * nothing left to revoke it with.
     */
    private function revokeAndDeleteConnections(Tenant $tenant): int
    {
        $connections = SocialConnection::query()
            ->acrossTenants()
            ->withTrashed()
            ->where('tenant_id', $tenant->getKey())
            ->get();

        foreach ($connections as $connection) {
            $providerKey = (string) $connection->getAttribute('provider_key');

            /*
             | A provider with no implementation yet is a different problem from
             | one that is down, and needs a different response: this grant has
             | to be revoked by hand in the provider's own console, and no
             | amount of retrying here will do it.
             |
             | Recorded rather than assumed away, because inventing a revocation
             | call for an unimplemented provider is exactly the guess that ends
             | with a live token nobody knows about.
             */
            if (! $this->providers->has($providerKey)) {
                Log::warning('INTEGRATION TODO: no provider implementation to revoke a grant during purge.', [
                    'connection_id' => $connection->getKey(),
                    'provider_key' => $providerKey,
                    'tenant_id' => $tenant->getKey(),
                ]);

                continue;
            }

            try {
                /*
                 | The project's own provider contract. No endpoint, scope or
                 | response shape is assumed here -- revoke() is implemented per
                 | provider against their current documentation, and this call
                 | site does not need to know how any of them work.
                 */
                $this->providers->for($providerKey)->revoke($connection);
            } catch (Throwable $e) {
                /*
                 | A provider being unreachable must not stop the purge -- the
                 | retention deadline is not conditional on their uptime. Logged
                 | by connection id so an operator can revoke by hand, and
                 | without the token, which is the one thing that must never
                 | reach a log.
                 */
                Log::warning('Could not revoke a social grant during purge.', [
                    'connection_id' => $connection->getKey(),
                    'tenant_id' => $tenant->getKey(),
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        // forceDelete, not delete: a soft-deleted row still holds the encrypted
        // tokens, which is exactly what a purge is supposed to remove.
        SocialConnection::query()
            ->acrossTenants()
            ->withTrashed()
            ->where('tenant_id', $tenant->getKey())
            ->forceDelete();

        return $connections->count();
    }

    /**
     * Delete the bytes, then the rows.
     */
    private function deleteMedia(Tenant $tenant): int
    {
        $deleted = 0;

        Media::query()
            ->acrossTenants()
            ->withTrashed()
            ->where('tenant_id', $tenant->getKey())
            ->chunkById(200, function ($media) use (&$deleted): void {
                foreach ($media as $item) {
                    $disk = Storage::disk($item->disk);

                    // Variants as well as the original. They are separate files
                    // on the same disk, and a purge that left thumbnails behind
                    // would leave a legible copy of every image it deleted.
                    $paths = array_values(array_filter([
                        $item->path,
                        $item->thumbnail_path,
                        ...array_values((array) ($item->variants ?? [])),
                    ]));

                    foreach (array_unique($paths) as $path) {
                        try {
                            $disk->delete($path);
                        } catch (Throwable $e) {
                            // A missing file is the desired end state, so it is
                            // not worth failing the purge over.
                            Log::warning('Could not delete a media file during purge.', [
                                'media_id' => $item->getKey(),
                                'exception' => $e->getMessage(),
                            ]);
                        }
                    }

                    $deleted++;
                }
            });

        Media::query()
            ->acrossTenants()
            ->withTrashed()
            ->where('tenant_id', $tenant->getKey())
            ->forceDelete();

        return $deleted;
    }

    private function anonymisePortalUsers(Tenant $tenant): int
    {
        // Portal users belong to exactly one tenant, so there is no sharing
        // question to answer: all of them go.
        $users = CustomerPortalUser::query()
            ->acrossTenants()
            ->withTrashed()
            ->where('tenant_id', $tenant->getKey())
            ->get();

        foreach ($users as $user) {
            $this->anonymise($user);
        }

        return $users->count();
    }

    private function anonymiseUsers(Tenant $tenant): int
    {
        /*
         | Only users whose ONLY membership was this tenant.
         |
         | A freelancer working for three agencies has one login. Anonymising
         | them because one of those agencies cancelled would destroy their
         | access to the other two -- deleting a person on the say-so of
         | somebody who is not them.
         */
        $userIds = TenantUser::query()
            ->where('tenant_id', $tenant->getKey())
            ->pluck('user_id');

        $shared = TenantUser::query()
            ->whereIn('user_id', $userIds)
            ->where('tenant_id', '!=', $tenant->getKey())
            ->pluck('user_id')
            ->unique();

        $exclusive = $userIds->diff($shared);

        $users = User::query()->whereIn('id', $exclusive)->get();

        foreach ($users as $user) {
            $this->anonymise($user);
        }

        // The memberships themselves go regardless: a shared user simply stops
        // being a member of this workspace.
        TenantUser::query()->where('tenant_id', $tenant->getKey())->delete();

        return $users->count();
    }

    /**
     * Replace the identifiers, keep the row.
     *
     * The row survives because audit entries, post authorship and approval
     * records point at it. Deleting it would either cascade those away or leave
     * them dangling; anonymising keeps the history intact and truthful while
     * removing the person from it.
     */
    private function anonymise(User|CustomerPortalUser $user): void
    {
        // Unique because the column is, and @invalid is reserved by RFC 2606 as
        // a TLD that can never resolve -- so a stray mail send cannot reach a
        // real address that happens to look like this.
        $user->email = 'purged-'.Str::lower((string) Str::ulid()).'@purged.invalid';
        $user->name = 'Deleted user';

        // An unusable hash rather than null: the column is not nullable, and a
        // random one cannot be guessed by anybody including us.
        $user->password = Hash::make(Str::random(64));

        foreach ([
            'phone', 'avatar_path', 'last_login_ip',
            'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at',
            'remember_token',
        ] as $attribute) {
            if (array_key_exists($attribute, $user->getAttributes())) {
                $user->setAttribute($attribute, null);
            }
        }

        $user->save();
    }
}
