<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Analytics\Models\PostMetric;
use App\Domain\Publishing\Models\PostTarget;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PostMetric> */
class PostMetricFactory extends Factory
{
    protected $model = PostMetric::class;

    public function definition(): array
    {
        return [
            // Lazily resolved so a caller-supplied tenant_id is honoured and
            // the target is created inside the SAME tenant.
            'tenant_id' => fn (): int => Tenant::factory()->create()->getKey(),

            'post_target_id' => fn (array $attributes): int => PostTarget::factory()
                ->create(['tenant_id' => $attributes['tenant_id']])
                ->getKey(),

            'customer_id' => fn (array $attributes): int => PostTarget::query()
                ->acrossTenants()
                ->with('post')
                ->find($attributes['post_target_id'])
                ->post
                ->customer_id,

            'social_account_id' => fn (array $attributes): int => PostTarget::query()
                ->acrossTenants()
                ->whereKey($attributes['post_target_id'])
                ->value('social_account_id'),

            'provider_key' => 'fake',

            'impressions' => fake()->numberBetween(100, 10000),
            'reach' => fake()->numberBetween(80, 9000),
            'likes' => fake()->numberBetween(0, 500),
            'comments' => fake()->numberBetween(0, 80),
            'shares' => fake()->numberBetween(0, 60),
            'saves' => fake()->numberBetween(0, 40),
            'clicks' => fake()->numberBetween(0, 300),
            'video_views' => null,

            'raw' => null,
            'collected_at' => now()->startOfMinute(),
        ];
    }

    /** Attach to a target that already exists. */
    public function forTarget(PostTarget $target): self
    {
        return $this->state(fn (): array => [
            'tenant_id' => $target->tenant_id,
            'post_target_id' => $target->getKey(),
            'customer_id' => $target->post->customer_id,
            'social_account_id' => $target->social_account_id,
            'provider_key' => $target->provider_key,
        ]);
    }

    /**
     * A network that reports nothing.
     *
     * Null is not zero: "not reported" and "reported as none" are different
     * facts, and only one of them belongs in an average.
     */
    public function unreported(): self
    {
        return $this->state(fn (): array => [
            'impressions' => null,
            'reach' => null,
            'likes' => null,
            'comments' => null,
            'shares' => null,
            'saves' => null,
            'clicks' => null,
            'video_views' => null,
        ]);
    }
}
