<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Customers\Models\Customer;
use App\Domain\Publishing\Enums\PostStatus;
use App\Domain\Publishing\Models\Post;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Post> */
class PostFactory extends Factory
{
    protected $model = Post::class;

    public function definition(): array
    {
        return [
            // Lazily resolved so a caller-supplied tenant_id is honoured and
            // the brand lands in the SAME tenant.
            'tenant_id' => fn (): int => Tenant::factory()->create()->getKey(),
            'customer_id' => fn (array $attributes): int => Customer::factory()
                ->create(['tenant_id' => $attributes['tenant_id']])
                ->getKey(),
            'title' => fake()->sentence(4),
            'body' => fake()->paragraph(),
            'content_type' => 'text',
            'status' => PostStatus::Draft->value,
            'approval_required' => true,
            'timezone' => 'Asia/Kolkata',
        ];
    }

    public function forCustomer(Customer $customer): static
    {
        return $this->state(fn (): array => [
            'tenant_id' => $customer->tenant_id,
            'customer_id' => $customer->getKey(),
            'timezone' => $customer->timezone,
        ]);
    }

    public function status(PostStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status->value]);
    }

    public function scheduledFor(\DateTimeInterface $when): static
    {
        return $this->state(fn (): array => [
            'status' => PostStatus::Scheduled->value,
            'scheduled_at' => $when,
        ]);
    }
}
