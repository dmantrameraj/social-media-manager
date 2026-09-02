<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\AI\Models\AutopilotSetting;
use App\Domain\Customers\Models\Customer;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AutopilotSetting> */
class AutopilotSettingFactory extends Factory
{
    protected $model = AutopilotSetting::class;

    public function definition(): array
    {
        return [
            'tenant_id' => fn (): int => Tenant::factory()->create()->getKey(),
            'customer_id' => fn (array $attributes): int => Customer::factory()
                ->create(['tenant_id' => $attributes['tenant_id']])
                ->getKey(),

            // Off by default, matching the model: autopilot never runs for a
            // brand nobody switched it on for.
            'enabled' => false,
            'posts_per_week' => 3,
            'themes' => ['Behind the scenes', 'Customer stories'],
        ];
    }

    public function forCustomer(Customer $customer): static
    {
        return $this->state(fn (): array => [
            'tenant_id' => $customer->tenant_id,
            'customer_id' => $customer->getKey(),
        ]);
    }

    public function enabled(): static
    {
        return $this->state(fn (): array => ['enabled' => true]);
    }

    public function due(): static
    {
        return $this->state(fn (): array => [
            'enabled' => true,
            'next_run_at' => now()->subHour(),
        ]);
    }
}
