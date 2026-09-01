<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Customers\Models\Customer;
use App\Domain\Social\Enums\AccountHealth;
use App\Domain\Social\Enums\AccountStatus;
use App\Domain\Social\Enums\SocialAccountType;
use App\Domain\Social\Models\SocialAccount;
use App\Domain\Social\Models\SocialConnection;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<SocialAccount> */
class SocialAccountFactory extends Factory
{
    protected $model = SocialAccount::class;

    public function definition(): array
    {
        return [
            // Resolved lazily so a caller-supplied tenant_id is honoured and
            // the brand and connection are created inside the SAME tenant.
            // Defaulting to independent factories would satisfy the foreign
            // keys while producing incoherent cross-tenant rows.
            'tenant_id' => fn (): int => Tenant::factory()->create()->getKey(),

            'customer_id' => fn (array $attributes): int => Customer::factory()
                ->create(['tenant_id' => $attributes['tenant_id']])
                ->getKey(),

            'social_connection_id' => fn (array $attributes): int => SocialConnection::factory()
                ->create([
                    'tenant_id' => $attributes['tenant_id'],
                    'customer_id' => $attributes['customer_id'],
                ])
                ->getKey(),

            'provider_key' => 'fake',
            'account_type' => SocialAccountType::Page->value,
            'external_id' => 'acct-'.Str::random(10),
            'name' => fake()->company(),
            'capabilities' => [
                'features' => ['text' => true, 'images' => true],
                'limits' => ['text_max' => 1000],
                'granted_scopes' => ['fake.publish'],
            ],
            'scopes' => ['fake.publish'],
            'status' => AccountStatus::Active->value,
            'health' => AccountHealth::Healthy->value,
        ];
    }

    /** Keeps tenant, brand and connection consistent with an existing brand. */
    public function forCustomer(Customer $customer): static
    {
        return $this->state(function () use ($customer): array {
            $connection = SocialConnection::factory()->create([
                'tenant_id' => $customer->tenant_id,
                'customer_id' => $customer->getKey(),
            ]);

            return [
                'tenant_id' => $customer->tenant_id,
                'customer_id' => $customer->getKey(),
                'social_connection_id' => $connection->getKey(),
            ];
        });
    }

    public function disconnected(): static
    {
        return $this->state(fn (): array => [
            'status' => AccountStatus::Disconnected->value,
        ]);
    }
}
