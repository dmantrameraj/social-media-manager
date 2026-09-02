<?php

declare(strict_types=1);

namespace App\Domain\Platform\Services;

use App\Domain\Audit\AuditLogger;
use App\Domain\Billing\Entitlements\EntitlementResolver;
use App\Domain\Billing\Entitlements\Enums\EntitlementType;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Per-tenant entitlement overrides.
 *
 * The point of an override is "the plan says 10, this customer gets 100"
 * without inventing a bespoke plan for one account. It is the highest-priority
 * source in EntitlementResolver, which makes it the single easiest way to give
 * product away by accident -- so it is audited, requires a reason, and only
 * accepts keys the system actually knows about.
 */
final class EntitlementOverrideService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly EntitlementResolver $entitlements,
    ) {}

    /**
     * Create or replace one override.
     *
     * A null $value means unlimited, which is only meaningful for the
     * Unlimited type; passing null with a Limit type would resolve to a
     * ceiling of zero and lock the tenant out of the feature entirely, so it
     * is refused rather than stored.
     */
    public function set(
        Tenant $tenant,
        string $key,
        EntitlementType $type,
        ?int $value,
        string $reason,
        User $actor,
        ?Carbon $expiresAt = null,
    ): void {
        $this->assertKnownKey($key);
        $this->assertReason($reason);

        if ($type !== EntitlementType::Unlimited && $value === null) {
            throw new InvalidArgumentException(
                "Entitlement [{$key}] is a {$type->value}, so it needs a value. "
                .'A null value only means "unlimited" for the unlimited type.',
            );
        }

        if ($value !== null && $value < 0) {
            throw new InvalidArgumentException('An entitlement override cannot be negative.');
        }

        DB::transaction(function () use ($tenant, $key, $type, $value, $reason, $actor, $expiresAt): void {
            $existing = DB::table('subscription_overrides')
                ->where('tenant_id', $tenant->getKey())
                ->where('key', $key)
                ->first();

            DB::table('subscription_overrides')->updateOrInsert(
                ['tenant_id' => $tenant->getKey(), 'key' => $key],
                [
                    'value_type' => $type->value,
                    'value' => $value,
                    'reason' => $reason,
                    'expires_at' => $expiresAt,
                    'created_by_user_id' => $actor->getKey(),
                    'updated_at' => now(),
                    'created_at' => $existing->created_at ?? now(),
                ],
            );

            $this->entitlements->forget($tenant, $key);

            $this->audit->log(
                $existing === null ? 'entitlement.override_created' : 'entitlement.override_updated',
                $tenant,
                oldValues: $existing === null ? null : [
                    'key' => $key,
                    'value_type' => $existing->value_type,
                    'value' => $existing->value,
                ],
                newValues: [
                    'key' => $key,
                    'value_type' => $type->value,
                    'value' => $value,
                    'expires_at' => $expiresAt?->toIso8601String(),
                    'reason' => $reason,
                ],
                actor: $actor,
                tenantId: $tenant->getKey(),
            );
        });
    }

    /** Drop an override, returning the tenant to whatever its plan grants. */
    public function clear(Tenant $tenant, string $key, string $reason, User $actor): void
    {
        $this->assertKnownKey($key);
        $this->assertReason($reason);

        DB::transaction(function () use ($tenant, $key, $reason, $actor): void {
            $existing = DB::table('subscription_overrides')
                ->where('tenant_id', $tenant->getKey())
                ->where('key', $key)
                ->first();

            if ($existing === null) {
                return;
            }

            DB::table('subscription_overrides')
                ->where('tenant_id', $tenant->getKey())
                ->where('key', $key)
                ->delete();

            $this->entitlements->forget($tenant, $key);

            $this->audit->log(
                'entitlement.override_removed',
                $tenant,
                oldValues: [
                    'key' => $key,
                    'value_type' => $existing->value_type,
                    'value' => $existing->value,
                ],
                newValues: ['reason' => $reason],
                actor: $actor,
                tenantId: $tenant->getKey(),
            );
        });
    }

    /**
     * Overrides currently in force for a tenant, newest first.
     *
     * @return array<int, object>
     */
    public function forTenant(Tenant $tenant): array
    {
        return DB::table('subscription_overrides')
            ->where('tenant_id', $tenant->getKey())
            ->orderBy('key')
            ->get()
            ->all();
    }

    /**
     * A typo must not silently create an override nothing will ever read.
     *
     * NOT config("entitlements.keys.{$key}"): entitlement keys contain dots,
     * which the config helper reads as nested traversal.
     */
    private function assertKnownKey(string $key): void
    {
        if (! array_key_exists($key, (array) config('entitlements.keys', []))) {
            throw new InvalidArgumentException("Unknown entitlement key [{$key}].");
        }
    }

    private function assertReason(string $reason): void
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('An entitlement override requires a stated reason.');
        }
    }
}
