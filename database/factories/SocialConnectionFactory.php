<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Social\Enums\ConnectionStatus;
use App\Domain\Social\Models\SocialConnection;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<SocialConnection> */
class SocialConnectionFactory extends Factory
{
    protected $model = SocialConnection::class;

    public function definition(): array
    {
        return [
            'tenant_id' => fn (): int => Tenant::factory()->create()->getKey(),
            'provider_key' => 'fake',
            'external_user_id' => 'ext-'.Str::random(10),
            'name' => fake()->name(),
            'scopes' => ['fake.publish'],
            'access_token' => 'token-'.Str::random(20),
            'refresh_token' => 'refresh-'.Str::random(20),
            'expires_at' => now()->addDays(30),
            'status' => ConnectionStatus::Active->value,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'status' => ConnectionStatus::NeedsReconnect->value,
            'expires_at' => now()->subDay(),
        ]);
    }
}
