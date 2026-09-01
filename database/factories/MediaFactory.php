<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Customers\Models\Customer;
use App\Domain\Media\Enums\MediaStatus;
use App\Domain\Media\Models\Media;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Media>
 */
class MediaFactory extends Factory
{
    protected $model = Media::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'customer_id' => Customer::factory(),
            'disk' => 'local',
            'path' => 'media/'.Str::ulid()->toString().'.jpg',
            'original_name' => fake()->word().'.jpg',
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
            'size_bytes' => fake()->numberBetween(10_000, 5_000_000),
            'width' => 1080,
            'height' => 1080,
            'checksum' => hash('sha256', Str::random(32)),
            'status' => MediaStatus::Ready,
        ];
    }

    /** Keeps tenant_id consistent between the media row and its customer. */
    public function forCustomer(Customer $customer): static
    {
        return $this->state(fn (): array => [
            'tenant_id' => $customer->tenant_id,
            'customer_id' => $customer->getKey(),
        ]);
    }

    public function processing(): static
    {
        return $this->state(fn (): array => [
            'status' => MediaStatus::Processing,
        ]);
    }
}
