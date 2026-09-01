<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Publishing\Enums\TargetStatus;
use App\Domain\Publishing\Models\Post;
use App\Domain\Publishing\Models\PostTarget;
use App\Domain\Social\Models\SocialAccount;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<PostTarget> */
class PostTargetFactory extends Factory
{
    protected $model = PostTarget::class;

    public function definition(): array
    {
        return [
            // Lazily resolved so a caller-supplied tenant_id is honoured and
            // the post and account are created inside the SAME tenant.
            'tenant_id' => fn (): int => Tenant::factory()->create()->getKey(),

            'social_account_id' => fn (array $attributes): int => SocialAccount::factory()
                ->create(['tenant_id' => $attributes['tenant_id']])
                ->getKey(),

            'post_id' => fn (array $attributes): int => Post::factory()
                ->create([
                    'tenant_id' => $attributes['tenant_id'],
                    'customer_id' => SocialAccount::query()->acrossTenants()
                        ->whereKey($attributes['social_account_id'])
                        ->value('customer_id'),
                ])
                ->getKey(),

            'provider_key' => 'fake',
            'status' => TargetStatus::Scheduled->value,
            'scheduled_at' => now()->subMinute(),
            'attempts' => 0,
            'max_attempts' => (int) config('publishing.max_attempts', 3),
            // Stable per target, and unique across the table.
            'idempotency_key' => hash('sha256', Str::ulid()->toString()),
        ];
    }

    /** Named `targeting` because Factory::for() is reserved by Laravel. */
    public function targeting(Post $post, SocialAccount $account): static
    {
        return $this->state(fn (): array => [
            'tenant_id' => $post->tenant_id,
            'post_id' => $post->getKey(),
            'social_account_id' => $account->getKey(),
            'provider_key' => $account->provider_key,
        ]);
    }

    public function status(TargetStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status->value]);
    }
}
