<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Billing\Enums\PaymentGateway;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Customers\Services\CreateCustomerService;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Services\ProvisionTenantService;
use App\Support\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * A working agency to log into after `migrate:fresh --seed`.
 *
 * The Phase 1 sign-off asks for this and it did not exist: a fresh clone had no
 * plans, no roles and no tenant, so the first thing anyone could do with the
 * application was register an account and discover which parts were reachable.
 *
 * Everything goes through the real services -- `ProvisionTenantService` and
 * `CreateCustomerService` -- rather than raw inserts, so the demo tenant has the
 * same roles, credit account and system media folders a signup produces. A
 * hand-built fixture would drift from the real thing and hide exactly the bugs
 * a demo environment exists to surface.
 */
final class DemoTenantSeeder extends Seeder
{
    public function run(ProvisionTenantService $provision, CreateCustomerService $brands): void
    {
        /*
         | Never in production. A demo login is a known account on a public host,
         | and `db:seed` is one careless deploy hook away from running there.
         | Refusing is cheap; explaining the breach is not.
         */
        if (app()->isProduction()) {
            $this->command->warn('Skipping the demo tenant: this is a production environment.');

            return;
        }

        $email = (string) config('platform.demo.email', 'demo@example.test');

        if (User::query()->where('email', $email)->exists()) {
            $this->command->info("Demo user {$email} already exists; leaving it alone.");

            return;
        }

        /*
         | Random unless one is supplied, and printed once. A fixed default
         | password in a seeder becomes the password on somebody's staging box,
         | and staging boxes are reachable.
         */
        $password = (string) (config('platform.demo.password') ?? Str::password(16));

        $owner = User::query()->create([
            'name' => 'Demo Owner',
            'email' => $email,
            'password' => Hash::make($password),
            'email_verified_at' => now(),
        ]);

        $tenant = $provision->execute($owner, 'Demo Agency');

        // The services below resolve the active tenant from context, exactly as
        // a request would.
        app(TenantContext::class)->set($tenant);

        $brand = $brands->execute($tenant->fresh(), $owner->fresh(), [
            'name' => 'Roast House Coffee',
            'industry' => 'Food and drink',
            'timezone' => 'Europe/London',
        ]);

        $this->subscribeToStarter($tenant->getKey());

        $this->command->newLine();
        $this->command->info('Demo agency ready:');
        $this->command->line('  URL       /login');
        $this->command->line("  Email     {$email}");
        $this->command->line("  Password  {$password}");
        $this->command->line("  Brand     {$brand->name}");
        $this->command->newLine();
    }

    /**
     * Put the demo tenant on a real plan.
     *
     * Without a subscription every entitlement resolves from
     * `config('entitlements.defaults')`, so the demo would exercise the fallback
     * path rather than the one paying customers are on -- and plan limits, the
     * thing most worth seeing work, would not be in play at all.
     */
    private function subscribeToStarter(int $tenantId): void
    {
        $planId = DB::table('plans')->where('slug', 'starter')->value('id');

        if ($planId === null) {
            $this->command->warn('No starter plan found; skipping the demo subscription.');

            return;
        }

        DB::table('subscriptions')->insert([
            'ulid' => (string) Str::ulid(),
            'tenant_id' => $tenantId,
            'plan_id' => $planId,
            // Null on purpose: no price is seeded, and plan_price_id is nullable
            // precisely so a manually-activated tenant works without one.
            'plan_price_id' => null,
            'status' => SubscriptionStatus::Active->value,
            'gateway' => PaymentGateway::Manual->value,
            'quantity' => 1,
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
            'notes' => 'Created by DemoTenantSeeder.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
