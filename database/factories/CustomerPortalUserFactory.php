<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\CustomerPortalUser;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<CustomerPortalUser>
 */
class CustomerPortalUserFactory extends Factory
{
    protected $model = CustomerPortalUser::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'status' => UserStatus::Active,
            'timezone' => 'Asia/Kolkata',
            'remember_token' => Str::random(10),
        ];
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn (): array => [
            'tenant_id' => $tenant->getKey(),
        ]);
    }

    public function unverified(): static
    {
        return $this->state(fn (): array => [
            'email_verified_at' => null,
        ]);
    }
}
