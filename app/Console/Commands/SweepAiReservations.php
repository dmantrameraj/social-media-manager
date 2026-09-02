<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\AI\Services\SweepStaleReservationsService;
use Illuminate\Console\Command;

/**
 * Returns credits held by generations that never finished.
 *
 * Runs every ten minutes: a stranded reservation costs a tenant real spending
 * power, so it should not sit for an hour.
 */
final class SweepAiReservations extends Command
{
    protected $signature = 'ai:sweep-reservations';

    protected $description = 'Release AI credits held by generations that never completed.';

    public function handle(SweepStaleReservationsService $sweeper): int
    {
        $result = $sweeper->execute();

        $this->info(sprintf(
            'Swept %d stale reservation(s), returning %d credit(s).',
            $result['swept'],
            $result['credits_released'],
        ));

        return self::SUCCESS;
    }
}
