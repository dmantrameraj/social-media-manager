<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Access\Services\SyncPermissionCatalogueService;
use Illuminate\Database\Seeder;

/**
 * Idempotent. Safe to run on every deploy -- and it must be, because the
 * permission table is a projection of config/permissions.php.
 */
class PermissionSeeder extends Seeder
{
    public function run(SyncPermissionCatalogueService $sync): void
    {
        $result = $sync->execute();

        $this->command->info(
            "Permissions synced: {$result['created']} created, {$result['existing']} already present."
        );

        $orphans = $sync->orphans();

        if ($orphans !== []) {
            // Reported, never auto-deleted: removing a permission revokes
            // access for everyone holding it.
            $this->command->warn(
                'Permissions in the database but not in the catalogue: '.implode(', ', $orphans)
            );
        }
    }
}
