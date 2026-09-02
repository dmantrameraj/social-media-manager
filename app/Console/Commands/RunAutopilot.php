<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\AI\Services\AutopilotService;
use Illuminate\Console\Command;

/**
 * Generates autopilot drafts for brands whose cadence has come around.
 *
 * Hourly, not daily: cadences are per brand and spread across the week, so a
 * daily run would bunch everything into one moment.
 */
final class RunAutopilot extends Command
{
    protected $signature = 'ai:run-autopilot';

    protected $description = 'Generate autopilot draft content for brands that are due.';

    public function handle(AutopilotService $autopilot): int
    {
        if (! config('features.autopilot', false)) {
            // A global kill switch, independent of per-brand opt-in.
            $this->info('Autopilot is disabled globally.');

            return self::SUCCESS;
        }

        $result = $autopilot->run();

        $this->info(sprintf(
            'Autopilot: %d brand(s) due, %d draft(s) created, %d skipped.',
            $result['brands'],
            $result['drafts'],
            $result['skipped'],
        ));

        return self::SUCCESS;
    }
}
