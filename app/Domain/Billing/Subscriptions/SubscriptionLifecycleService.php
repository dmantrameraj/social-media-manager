<?php

declare(strict_types=1);

namespace App\Domain\Billing\Subscriptions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Billing\Entitlements\EntitlementResolver;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Drives tenants and subscriptions through their lifecycle.
 *
 * Runs from `billing:process-lifecycle` on an hourly schedule and is fully
 * idempotent: running it twice in one hour must change nothing the second
 * time, because a scheduler that overlaps or retries is normal.
 *
 * Manual and gateway-backed subscriptions traverse identical transitions --
 * ManualGateway implements the same interface, so there is no branch here.
 * See docs/09-BILLING.md §5.
 */
final class SubscriptionLifecycleService
{
    public function __construct(
        private readonly EntitlementResolver $entitlements,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{trial_expired: int, period_expired: int, suspended: int}
     */
    public function run(): array
    {
        return [
            'trial_expired' => $this->expireTrials(),
            'period_expired' => $this->expirePeriods(),
            'suspended' => $this->suspendAfterGrace(),
        ];
    }

    /**
     * Trial ended with nothing to bill against -> grace.
     *
     * Grace rather than immediate suspension: a trial that ends on a Friday
     * night should not lock the customer out before anyone can act.
     */
    private function expireTrials(): int
    {
        $count = 0;

        Tenant::query()
            ->where('status', TenantStatus::Trialing->value)
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<=', now())
            ->each(function (Tenant $tenant) use (&$count): void {
                $hasPaidSubscription = Subscription::query()
                    ->acrossTenants()
                    ->where('tenant_id', $tenant->getKey())
                    ->whereIn('status', [
                        SubscriptionStatus::Active->value,
                        SubscriptionStatus::PastDue->value,
                    ])
                    ->exists();

                if ($hasPaidSubscription) {
                    $this->transition($tenant, TenantStatus::Active, 'tenant.trial_converted');

                    return;
                }

                $this->enterGrace($tenant, 'tenant.trial_expired');
                $count++;
            });

        return $count;
    }

    /** A billing period lapsed without renewal -> grace. */
    private function expirePeriods(): int
    {
        $count = 0;

        Subscription::query()
            ->acrossTenants()
            ->whereIn('status', [
                SubscriptionStatus::Active->value,
                SubscriptionStatus::PastDue->value,
            ])
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<=', now())
            ->each(function (Subscription $subscription) use (&$count): void {
                // Tenant is the root of the hierarchy and carries no tenant
                // scope, so there is nothing to bypass here.
                $tenant = Tenant::query()->find($subscription->tenant_id);

                if ($tenant === null) {
                    return;
                }

                DB::transaction(function () use ($subscription, $tenant): void {
                    $subscription->forceFill([
                        'status' => SubscriptionStatus::Grace->value,
                        'grace_ends_at' => now()->addDays((int) config('billing.grace_days', 7)),
                    ])->save();

                    $this->enterGrace($tenant, 'subscription.period_expired');
                });

                $count++;
            });

        return $count;
    }

    /** Grace elapsed -> suspended, and the retention clock starts. */
    private function suspendAfterGrace(): int
    {
        $count = 0;

        Tenant::query()
            ->where('status', TenantStatus::Grace->value)
            ->each(function (Tenant $tenant) use (&$count): void {
                $graceEnds = Subscription::query()
                    ->acrossTenants()
                    ->where('tenant_id', $tenant->getKey())
                    ->whereNotNull('grace_ends_at')
                    ->orderByDesc('grace_ends_at')
                    ->value('grace_ends_at');

                // A trial that lapsed has no subscription row, so fall back to
                // the trial end date plus the grace window.
                $deadline = $graceEnds !== null
                    ? Carbon::parse($graceEnds)
                    : $tenant->trial_ends_at?->copy()->addDays((int) config('billing.grace_days', 7));

                if ($deadline === null || $deadline->isFuture()) {
                    return;
                }

                DB::transaction(function () use ($tenant): void {
                    $tenant->forceFill([
                        'status' => TenantStatus::Suspended->value,
                        'suspended_at' => now(),
                    ])->save();

                    $this->entitlements->forget($tenant);

                    $this->audit->log('tenant.suspended', $tenant, newValues: [
                        'status' => TenantStatus::Suspended->value,
                    ]);
                });

                $count++;
            });

        return $count;
    }

    /**
     * Reactivate after payment. Clears the retention clock, because a tenant
     * that paid must not be queued for anonymisation.
     */
    public function reactivate(Tenant $tenant): Tenant
    {
        return DB::transaction(function () use ($tenant): Tenant {
            $tenant->forceFill([
                'status' => TenantStatus::Active->value,
                'suspended_at' => null,
                'cancelled_at' => null,
                'purge_after' => null,
            ])->save();

            $this->entitlements->forget($tenant);

            $this->audit->log('tenant.reactivated', $tenant, newValues: [
                'status' => TenantStatus::Active->value,
            ]);

            return $tenant;
        });
    }

    /**
     * Cancellation retains data for the configured window rather than deleting
     * it -- see docs/10-SECURITY.md §9.
     */
    public function cancel(Tenant $tenant): Tenant
    {
        return DB::transaction(function () use ($tenant): Tenant {
            $tenant->forceFill([
                'status' => TenantStatus::Cancelled->value,
                'cancelled_at' => now(),
                'purge_after' => now()->addDays((int) config('tenancy.retention_days', 60)),
            ])->save();

            Subscription::query()
                ->acrossTenants()
                ->where('tenant_id', $tenant->getKey())
                ->activeish()
                ->update([
                    'status' => SubscriptionStatus::Cancelled->value,
                    'cancelled_at' => now(),
                ]);

            $this->entitlements->forget($tenant);

            $this->audit->log('tenant.cancelled', $tenant, newValues: [
                'status' => TenantStatus::Cancelled->value,
                'purge_after' => $tenant->purge_after?->toDateTimeString(),
            ]);

            return $tenant;
        });
    }

    private function enterGrace(Tenant $tenant, string $action): void
    {
        DB::transaction(function () use ($tenant, $action): void {
            $tenant->forceFill(['status' => TenantStatus::Grace->value])->save();

            $this->entitlements->forget($tenant);
            $this->audit->log($action, $tenant, newValues: ['status' => TenantStatus::Grace->value]);
        });
    }

    private function transition(Tenant $tenant, TenantStatus $status, string $action): void
    {
        $tenant->forceFill(['status' => $status->value])->save();

        $this->entitlements->forget($tenant);
        $this->audit->log($action, $tenant, newValues: ['status' => $status->value]);
    }
}
