<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Audit\AuditLogger;
use App\Domain\Platform\Models\ImpersonationSession;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Proves the scheduler is alive, and closes impersonations that outlived their
 * ceiling.
 *
 * These share a command because they share a reason to run every minute, and
 * because the second is only trustworthy if the first is true: a sweeper that
 * silently stopped running looks exactly like a sweeper with nothing to do.
 */
final class PlatformHeartbeatCommand extends Command
{
    protected $signature = 'platform:heartbeat';

    protected $description = 'Record the scheduler heartbeat and close expired impersonation sessions';

    public function __construct(private readonly AuditLogger $audit)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->beat();
        $closed = $this->closeExpiredImpersonations();

        $this->info($closed === 0
            ? 'Heartbeat recorded.'
            : "Heartbeat recorded. Closed {$closed} expired impersonation session(s).");

        return self::SUCCESS;
    }

    /**
     * A plain timestamp under a long TTL.
     *
     * The TTL is deliberately far longer than the staleness threshold: the
     * dashboard needs to distinguish "beat a while ago" from "never beat", and
     * an entry that expired would collapse those two into the same blank.
     */
    private function beat(): void
    {
        Cache::put(
            (string) config('platform.health.cache_key', 'platform:scheduler:heartbeat'),
            now()->toIso8601String(),
            now()->addDay(),
        );
    }

    /**
     * Closes the database row for a session past its timeout.
     *
     * This is the backstop, not the primary enforcement: HandleImpersonation
     * ends the session on the impersonator's next request. That request may
     * never come -- an admin who closes the tab leaves the row open forever --
     * so the trail is corrected here.
     */
    private function closeExpiredImpersonations(): int
    {
        $expired = ImpersonationSession::query()->expired()->get();

        foreach ($expired as $session) {
            $session->forceFill(['ended_at' => now()])->save();

            $this->audit->log(
                'impersonation.expired',
                null,
                newValues: [
                    'impersonation_session_id' => $session->getKey(),
                    'target_user_id' => $session->target_id,
                    'elapsed_minutes' => $session->elapsedMinutes(),
                    'closed_by' => 'scheduler',
                ],
                actor: $session->superAdmin,
                tenantId: $session->tenant_id,
            );
        }

        return $expired->count();
    }
}
