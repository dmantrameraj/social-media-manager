<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\AI\Models\AiGeneration;
use Illuminate\Console\Command;

/**
 * Clears request and response snapshots past their retention window.
 *
 * The snapshots hold customer business content, not just diagnostics, so they
 * are not kept indefinitely. The generation ROW survives -- token counts and
 * cost stay measurable; only the content goes.
 *
 * See docs/08-AI-ARCHITECTURE.md §7.
 */
final class PurgeAiSnapshots extends Command
{
    protected $signature = 'ai:purge-snapshots';

    protected $description = 'Clear AI request/response snapshots past their retention window.';

    public function handle(): int
    {
        $purged = 0;

        AiGeneration::query()
            ->acrossTenants()
            ->snapshotsExpired()
            ->orderBy('id')
            ->chunkById(500, function ($generations) use (&$purged): void {
                foreach ($generations as $generation) {
                    $generation->forceFill([
                        'request_snapshot' => null,
                        'response_snapshot' => null,
                    ])->save();

                    $purged++;
                }
            });

        $this->info(sprintf(
            'Purged snapshots from %d generation(s) older than %d days.',
            $purged,
            (int) config('ai.snapshot_retention_days', 30),
        ));

        return self::SUCCESS;
    }
}
