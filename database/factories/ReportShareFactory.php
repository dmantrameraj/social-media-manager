<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Analytics\Models\ReportShare;
use App\Domain\Customers\Models\Customer;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ReportShare> */
class ReportShareFactory extends Factory
{
    protected $model = ReportShare::class;

    public function definition(): array
    {
        return [
            'tenant_id' => fn (): int => Tenant::factory()->create()->getKey(),

            'customer_id' => fn (array $attributes): int => Customer::factory()
                ->create(['tenant_id' => $attributes['tenant_id']])
                ->getKey(),

            'ulid' => fn (): string => (string) Str::ulid(),
            'token_hash' => fn (): string => ReportShare::newToken()['hash'],

            'window_from' => fn () => now()->subDays(30),
            'window_to' => fn () => now(),
            'expires_at' => fn () => now()->addDays(30),
            'revoked_at' => null,
            'view_count' => 0,
        ];
    }

    /** Past its deadline. */
    public function expired(): self
    {
        return $this->state(fn (): array => ['expires_at' => now()->subDay()]);
    }

    /** Withdrawn by the agency, which is a decision rather than a deadline. */
    public function revoked(): self
    {
        return $this->state(fn (): array => ['revoked_at' => now()->subHour()]);
    }
}
