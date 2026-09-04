<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Platform\Enums\DomainType;
use App\Domain\Platform\Enums\SslStatus;
use App\Domain\Platform\Models\Domain;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Domain> */
class DomainFactory extends Factory
{
    protected $model = Domain::class;

    public function definition(): array
    {
        return [
            'tenant_id' => fn (): int => Tenant::factory()->create()->getKey(),
            // Unique globally, as the table requires: a hostname maps to
            // exactly one agency.
            'hostname' => fn (): string => 'portal-'.Str::lower(Str::random(8)).'.test',
            'type' => DomainType::Custom->value,
            'is_primary' => false,
            'verification_token' => fn (): string => Domain::newVerificationToken(),
            'verified_at' => null,
            'ssl_status' => null,
        ];
    }

    /** Proven, so it may resolve a request to a tenant. */
    public function verified(): self
    {
        return $this->state(fn (): array => [
            'verified_at' => now(),
            'ssl_status' => SslStatus::Active->value,
        ]);
    }
}
