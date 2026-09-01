<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Customers\Models\Customer;
use App\Domain\Media\Models\MediaFolder;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MediaFolder>
 */
class MediaFolderFactory extends Factory
{
    protected $model = MediaFolder::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'customer_id' => Customer::factory(),
            // Unique suffix: the table has UNIQUE (customer_id, parent_id, name).
            'name' => fake()->word().'-'.Str::lower(Str::random(5)),
        ];
    }

    public function forCustomer(Customer $customer): static
    {
        return $this->state(fn (): array => [
            'tenant_id' => $customer->tenant_id,
            'customer_id' => $customer->getKey(),
        ]);
    }

    public function system(string $key): static
    {
        return $this->state(fn (): array => [
            'system_key' => $key,
            'name' => Str::headline($key),
        ]);
    }
}
