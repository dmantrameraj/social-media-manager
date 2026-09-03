<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Media\Enums\MediaStatus;
use App\Domain\Media\Jobs\GenerateMediaVariants;
use App\Domain\Media\Models\Media;
use Illuminate\Console\Command;

/**
 * Queues variant generation for images that have none.
 *
 * Needed because the job did not exist when the upload pipeline was written:
 * every image uploaded before it shipped is sitting in `processing`, which the
 * composer does not offer and publishing rejects. Without this they would stay
 * there, since nothing re-examines an old row.
 *
 * Also the recovery path for a batch that failed transiently -- a disk blip, a
 * worker killed mid-run -- where the files are fine and the job simply needs
 * running again.
 */
final class RegenerateMediaVariants extends Command
{
    protected $signature = 'media:regenerate-variants
                            {--tenant= : Restrict to one tenant id}
                            {--failed : Include images previously marked failed}';

    protected $description = 'Queue variant generation for images stuck without variants';

    public function handle(): int
    {
        $statuses = [MediaStatus::Processing->value];

        /*
         | Failed rows are opt-in. A permanently corrupt upload fails on every
         | pass, and sweeping them back in by default would mean every run of
         | this command re-queues the same doomed files for ever.
         */
        if ($this->option('failed')) {
            $statuses[] = MediaStatus::Failed->value;
        }

        // acrossTenants: this is console tooling with no request to resolve a
        // tenant from. --tenant narrows it deliberately when that is wanted.
        $query = Media::query()
            ->acrossTenants()
            ->whereIn('status', $statuses)
            ->where('mime_type', 'like', 'image/%');

        if ($tenant = $this->option('tenant')) {
            $query->where('tenant_id', (int) $tenant);
        }

        $queued = 0;

        // Chunked by id: dispatching mutates nothing the cursor depends on, but
        // a library can hold far more rows than should be loaded at once.
        $query->chunkById(200, function ($media) use (&$queued): void {
            foreach ($media as $item) {
                GenerateMediaVariants::dispatch($item->getKey());
                $queued++;
            }
        });

        $this->info($queued === 0
            ? 'No images needed variants.'
            : "Queued {$queued} ".str('image')->plural($queued).' for variant generation.');

        return self::SUCCESS;
    }
}
