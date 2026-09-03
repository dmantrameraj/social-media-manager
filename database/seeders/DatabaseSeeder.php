<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * What `migrate:fresh --seed` produces.
 *
 * This was still the Laravel skeleton's default -- one hardcoded "Test User"
 * and nothing else -- which meant a fresh clone had no permissions, no roles,
 * no plans and no tenant. Every entitlement resolved from config defaults, no
 * subscription could exist at all, and the first thing anyone could do with the
 * application was register and find out which parts worked.
 *
 * Order matters: permissions before the demo tenant, because provisioning
 * assigns roles that the catalogue has to have created first, and plans before
 * the demo tenant so it has something to subscribe to.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            // Both are idempotent and safe on every deploy. PermissionSeeder is
            // a projection of config/permissions.php; PlanSeeder inserts only
            // what is missing and never overwrites an admin's edit.
            PermissionSeeder::class,
            PlanSeeder::class,

            // Refuses to run in production on its own.
            DemoTenantSeeder::class,
        ]);
    }
}
