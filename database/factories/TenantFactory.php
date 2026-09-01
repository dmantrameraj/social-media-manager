<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Enums\TenantType;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'type' => TenantType::Agency,
            'status' => TenantStatus::Active,
            'timezone' => 'Asia/Kolkata',
            'locale' => 'en',
            'currency' => 'INR',
        ];
    }

    public function trialing(): static
    {
        return $this->state(fn (): array => [
            'status' => TenantStatus::Trialing,
            'trial_ends_at' => now()->addDays((int) config('tenancy.trial_days', 7)),
        ]);
    }

    public function grace(): static
    {
        return $this->state(fn (): array => [
            'status' => TenantStatus::Grace,
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => [
            'status' => TenantStatus::Suspended,
            'suspended_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'status' => TenantStatus::Cancelled,
            'cancelled_at' => now(),
            'purge_after' => now()->addDays((int) config('tenancy.retention_days', 60)),
        ]);
    }
}
