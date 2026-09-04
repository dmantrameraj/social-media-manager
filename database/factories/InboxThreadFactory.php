<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Engagement\Enums\InboxKind;
use App\Domain\Engagement\Enums\InboxStatus;
use App\Domain\Engagement\Models\InboxThread;
use App\Domain\Social\Models\SocialAccount;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<InboxThread> */
class InboxThreadFactory extends Factory
{
    protected $model = InboxThread::class;

    public function definition(): array
    {
        return [
            // Lazily resolved so a caller-supplied tenant_id is honoured and
            // the account is created inside the SAME tenant.
            'tenant_id' => fn (): int => Tenant::factory()->create()->getKey(),

            'social_account_id' => fn (array $attributes): int => SocialAccount::factory()
                ->create(['tenant_id' => $attributes['tenant_id']])
                ->getKey(),

            'customer_id' => fn (array $attributes): int => SocialAccount::query()
                ->acrossTenants()
                ->whereKey($attributes['social_account_id'])
                ->value('customer_id'),

            'ulid' => fn (): string => (string) Str::ulid(),
            'provider_key' => 'fake',
            'kind' => InboxKind::Comment->value,
            'external_thread_id' => fn (): string => 'thread-'.Str::random(12),
            'participant_name' => fn (): string => fake()->name(),
            'participant_external_id' => fn (): string => 'user-'.Str::random(10),
            'status' => InboxStatus::Open->value,
            'assigned_to_user_id' => null,
            'post_target_id' => null,
            'last_message_at' => fn () => now()->subHours(2),
            'last_synced_at' => fn () => now(),
        ];
    }

    public function forAccount(SocialAccount $account): self
    {
        return $this->state(fn (): array => [
            'tenant_id' => $account->tenant_id,
            'social_account_id' => $account->getKey(),
            'customer_id' => $account->customer_id,
            'provider_key' => $account->provider_key,
        ]);
    }

    public function status(InboxStatus $status): self
    {
        return $this->state(fn (): array => ['status' => $status->value]);
    }

    public function message(): self
    {
        return $this->state(fn (): array => ['kind' => InboxKind::Message->value]);
    }
}
