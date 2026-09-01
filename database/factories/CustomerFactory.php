<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Customers\Enums\CustomerStatus;
use App\Domain\Customers\Models\Customer;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            // Explicit rather than relying on TenantContext: tests must be
            // able to build a customer for a specific tenant without first
            // establishing context.
            'tenant_id' => Tenant::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'industry' => fake()->word(),
            'website' => fake()->url(),
            'timezone' => 'Asia/Kolkata',
            'status' => CustomerStatus::Active,
        ];
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn (): array => [
            'tenant_id' => $tenant->getKey(),
            'timezone' => $tenant->timezone,
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => [
            'status' => CustomerStatus::Archived,
        ]);
    }
}
