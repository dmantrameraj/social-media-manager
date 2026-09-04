<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Engagement\Enums\DeliveryStatus;
use App\Domain\Engagement\Enums\MessageDirection;
use App\Domain\Engagement\Models\InboxMessage;
use App\Domain\Engagement\Models\InboxThread;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<InboxMessage> */
class InboxMessageFactory extends Factory
{
    protected $model = InboxMessage::class;

    public function definition(): array
    {
        return [
            'tenant_id' => fn (): int => Tenant::factory()->create()->getKey(),

            'inbox_thread_id' => fn (array $attributes): int => InboxThread::factory()
                ->create(['tenant_id' => $attributes['tenant_id']])
                ->getKey(),

            'external_message_id' => fn (): string => 'msg-'.Str::random(12),
            'direction' => MessageDirection::Inbound->value,
            'is_internal' => false,
            'author_type' => null,
            'author_id' => null,
            'author_name' => fn (): string => fake()->name(),
            'body' => fn (): string => fake()->sentence(),
            // Inbound messages are delivered by definition: they arrived.
            'delivery_status' => DeliveryStatus::Delivered->value,
            'posted_at' => fn () => now()->subHour(),
        ];
    }

    public function inThread(InboxThread $thread): self
    {
        return $this->state(fn (): array => [
            'tenant_id' => $thread->tenant_id,
            'inbox_thread_id' => $thread->getKey(),
        ]);
    }

    public function outbound(): self
    {
        return $this->state(fn (): array => [
            'direction' => MessageDirection::Outbound->value,
            'author_name' => 'Your team',
        ]);
    }

    /** A note that never leaves the building. */
    public function internal(): self
    {
        return $this->state(fn (): array => [
            'direction' => MessageDirection::Outbound->value,
            'is_internal' => true,
            'external_message_id' => null,
        ]);
    }
}
