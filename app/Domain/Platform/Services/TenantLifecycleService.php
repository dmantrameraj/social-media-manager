<?php

declare(strict_types=1);

namespace App\Domain\Platform\Services;

use App\Domain\Audit\AuditLogger;
use App\Domain\Billing\Entitlements\EntitlementResolver;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Super Admin control over an agency's lifecycle.
 *
 * Every transition here is a manual platform action taken against someone
 * else's business, so all three carry the same contract:
 *
 *   - a reason is REQUIRED, not optional. "Why is this agency suspended?" has
 *     to be answerable months later, and a blank reason makes a legitimate
 *     action indistinguishable from an abuse of access.
 *   - the entitlement cache is invalidated, because status feeds limits.
 *   - an audit entry is written synchronously inside the transaction.
 *
 * Automatic, billing-driven transitions live in SubscriptionLifecycleService
 * instead. This class is only for a human deciding something.
 */
final class TenantLifecycleService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly EntitlementResolver $entitlements,
    ) {}

    /**
     * Take an agency offline. Product access stops; the account, its content
     * and its media are untouched.
     */
    public function suspend(Tenant $tenant, string $reason, User $actor): Tenant
    {
        $this->assertReason($reason);

        if ($tenant->status === TenantStatus::Suspended) {
            return $tenant;
        }

        return $this->transition(
            $tenant,
            TenantStatus::Suspended,
            'tenant.suspended_by_admin',
            $reason,
            $actor,
            ['suspended_at' => now()],
        );
    }

    /**
     * Put a suspended or cancelled agency back into service.
     *
     * Restores to Active rather than to whatever it was before: a trial that
     * lapsed months ago should not silently resume, and Active is the state a
     * human is actually choosing when they reactivate an account.
     */
    public function reactivate(Tenant $tenant, string $reason, User $actor): Tenant
    {
        $this->assertReason($reason);

        if ($tenant->permitsProductAccess()) {
            return $tenant;
        }

        return $this->transition(
            $tenant,
            TenantStatus::Active,
            'tenant.reactivated_by_admin',
            $reason,
            $actor,
            ['suspended_at' => null],
        );
    }

    /**
     * Manual activation, the sales flow in docs/09-BILLING.md §12: an agency
     * has paid outside the product and should stop being treated as a trial.
     */
    public function activate(Tenant $tenant, string $reason, User $actor): Tenant
    {
        $this->assertReason($reason);

        if ($tenant->status === TenantStatus::Active) {
            return $tenant;
        }

        return $this->transition(
            $tenant,
            TenantStatus::Active,
            'tenant.activated_by_admin',
            $reason,
            $actor,
            ['suspended_at' => null, 'trial_ends_at' => null],
        );
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function transition(
        Tenant $tenant,
        TenantStatus $to,
        string $action,
        string $reason,
        User $actor,
        array $extra = [],
    ): Tenant {
        $from = $tenant->status;

        return DB::transaction(function () use ($tenant, $to, $from, $action, $reason, $actor, $extra): Tenant {
            $tenant->forceFill(['status' => $to->value, ...$extra])->save();

            // Status feeds entitlement resolution, so a stale cache would keep
            // a suspended tenant's limits alive.
            $this->entitlements->forget($tenant);

            $this->audit->log(
                $action,
                $tenant,
                oldValues: ['status' => $from->value],
                newValues: ['status' => $to->value, 'reason' => $reason],
                actor: $actor,
                tenantId: $tenant->getKey(),
            );

            return $tenant->refresh();
        });
    }

    private function assertReason(string $reason): void
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'A lifecycle change against a tenant requires a stated reason.',
            );
        }
    }
}
