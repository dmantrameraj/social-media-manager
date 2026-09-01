<?php

declare(strict_types=1);

namespace App\Domain\Publishing\Services;

use App\Domain\Publishing\Enums\TargetStatus;
use App\Domain\Publishing\Models\PostTarget;
use Illuminate\Support\Str;

/**
 * Atomic claiming.
 *
 * The single most important correctness detail in the engine: a target must
 * never be dispatched twice, because that means a duplicate post on a client's
 * timeline.
 *
 * A conditional UPDATE with an affected-rows check is atomic in InnoDB and
 * needs no application lock, no cache lock, and no SELECT ... FOR UPDATE held
 * across a network call. That last point matters -- holding a row lock while
 * waiting on a social API is how you exhaust the connection pool.
 *
 * See docs/06-PUBLISHING-ENGINE.md §7.
 */
final class ClaimPostTargetService
{
    /**
     * Try to take ownership of a target.
     *
     * @return bool true if THIS caller won the claim
     */
    public function claim(PostTarget $target, ?string $workerId = null): bool
    {
        $worker = $workerId ?? $this->workerId();

        $claimed = PostTarget::query()
            ->acrossTenants()
            ->whereKey($target->getKey())
            // Both conditions matter: status alone would let a second worker
            // claim a row another had already moved but not yet locked.
            ->where('status', TargetStatus::Scheduled->value)
            ->whereNull('locked_at')
            ->update([
                'status' => TargetStatus::Processing->value,
                'locked_at' => now(),
                'locked_by' => $worker,
                'dispatched_at' => now(),
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            return false;
        }

        $target->refresh();

        return true;
    }

    /**
     * Release a claim back to scheduled, optionally deferring it.
     *
     * Used when a precondition fails after claiming -- rate limit hit,
     * connection unhealthy -- so the work returns to the queue rather than
     * being lost or counted as an attempt.
     */
    public function release(PostTarget $target, ?\DateTimeInterface $retryAt = null): void
    {
        PostTarget::query()
            ->acrossTenants()
            ->whereKey($target->getKey())
            ->update([
                'status' => TargetStatus::Scheduled->value,
                'locked_at' => null,
                'locked_by' => null,
                'next_attempt_at' => $retryAt,
                'updated_at' => now(),
            ]);

        $target->refresh();
    }

    /** Clear the lock without changing status -- used on terminal outcomes. */
    public function unlock(PostTarget $target): void
    {
        PostTarget::query()
            ->acrossTenants()
            ->whereKey($target->getKey())
            ->update(['locked_at' => null, 'locked_by' => null, 'updated_at' => now()]);
    }

    /**
     * Identifies which process holds a claim, for diagnosing a stuck target.
     */
    private function workerId(): string
    {
        return gethostname().':'.getmypid().':'.Str::random(6);
    }
}
