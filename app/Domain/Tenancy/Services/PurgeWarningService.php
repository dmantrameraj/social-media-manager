<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Services;

use App\Domain\Audit\AuditLogger;
use App\Domain\Notifications\TenantPurgeWarningNotification;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Log;

/**
 * Warns an agency before their data is destroyed.
 *
 * docs/10-SECURITY.md §9 has always said the purge is "preceded by warning
 * emails at 30 and 7 days", and config('tenancy.purge_warning_days') has held
 * [30, 7] since Phase 0. Nothing read it, so the purge shipped able to delete
 * an agency's entire history with no notice.
 *
 * The clock only starts at cancellation, so nobody loses data they did not
 * choose to stop paying for -- but the difference between a policy and an
 * ambush is whether anyone was told.
 */
final class PurgeWarningService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Send any warning now due for this tenant.
     *
     * @return int|null the stage warned about, or null if nothing was due
     */
    public function warn(Tenant $tenant): ?int
    {
        if ($tenant->purge_after === null || $tenant->purged_at !== null) {
            return null;
        }

        // Negative means the deadline has already passed, which is the purge's
        // problem rather than this one's.
        $daysRemaining = (int) ceil(now()->diffInDays($tenant->purge_after, false));

        if ($daysRemaining < 0) {
            return null;
        }

        /*
         | Int-keyed, because PHP casts a numeric string array key to an integer
         | whatever you write. Storing "30" and reading 30 works only because
         | both ends are cast identically -- saying so avoids the next person
         | reintroducing a (string) cast that reads as meaningful.
         */
        $sent = (array) ($tenant->purge_warnings_sent ?? []);

        /*
         | Every stage whose threshold has been crossed and not yet sent. More
         | than one can be outstanding at once -- if this job does not run for a
         | month, a tenant can pass 30 and 7 between two runs.
         */
        $due = [];

        foreach ((array) config('tenancy.purge_warning_days', []) as $stage) {
            $stage = (int) $stage;

            if ($daysRemaining <= $stage && ! isset($sent[$stage])) {
                $due[] = $stage;
            }
        }

        if ($due === []) {
            return null;
        }

        $owner = $tenant->owner;

        if ($owner === null) {
            /*
             | A tenant with no owner is about to lose everything with nobody to
             | tell. That is its own problem and is worth surfacing rather than
             | swallowing -- but it must not stop the sweep, and it must not
             | mark the warning sent, so a restored owner still gets one.
             */
            Log::warning('A tenant is approaching purge with no owner to warn.', [
                'tenant_id' => $tenant->getKey(),
                'days_remaining' => $daysRemaining,
            ]);

            return null;
        }

        /*
         | ONE message, quoting the real days remaining rather than the stage.
         |
         | If both 30 and 7 are outstanding, sending both would put two
         | contradictory deadlines in the same inbox on the same morning, and
         | the 30-day one would state a date that is already wrong. The stages
         | decide WHEN to speak, not what to say.
         */
        $owner->notify(new TenantPurgeWarningNotification(
            tenantName: $tenant->name,
            purgeAfter: $tenant->purge_after,
            daysRemaining: $daysRemaining,
        ));

        // Every crossed stage is recorded, including ones skipped over, so a
        // late run never triggers a second, staler warning afterwards.
        foreach ($due as $stage) {
            $sent[$stage] = now()->toIso8601String();
        }

        $tenant->purge_warnings_sent = $sent;
        $tenant->save();

        $mostUrgent = min($due);

        $this->audit->log(
            'tenancy.purge_warning_sent',
            $tenant,
            newValues: ['days_remaining' => $daysRemaining, 'stages' => $due],
            tenantId: $tenant->getKey(),
        );

        return $mostUrgent;
    }
}
