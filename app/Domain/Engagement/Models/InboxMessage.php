<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Models;

use App\Domain\Engagement\Enums\DeliveryStatus;
use App\Domain\Engagement\Enums\MessageDirection;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\InboxMessageFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One message in a conversation, in either direction.
 *
 * Internal notes live here too, exactly as PostComment does for a post.
 * Keeping notes in their own table reliably produces notes nobody reads,
 * because they are not where the conversation is.
 *
 * @property int $tenant_id
 * @property int $inbox_thread_id
 * @property string|null $external_message_id
 * @property MessageDirection $direction
 * @property bool $is_internal
 * @property string|null $author_type
 * @property int|null $author_id
 * @property string|null $author_name
 * @property string $body
 * @property DeliveryStatus $delivery_status
 * @property Carbon|null $posted_at
 */
#[UseFactory(InboxMessageFactory::class)]
class InboxMessage extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    /**
     * Nothing is fillable. `is_internal` in particular must never come from
     * request input -- an internal note posted as a public reply is a private
     * remark sent to a customer, and the reverse is a reply nobody sees.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'direction' => MessageDirection::class,
            'delivery_status' => DeliveryStatus::class,
            'is_internal' => 'boolean',
            'posted_at' => 'datetime',
        ];
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(InboxThread::class, 'inbox_thread_id');
    }

    /** @param  Builder<self>  $query */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_internal', false);
    }

    /**
     * Replies that never reached the platform.
     *
     * @param  Builder<self>  $query
     */
    public function scopeUndelivered(Builder $query): Builder
    {
        return $query
            ->where('direction', MessageDirection::Outbound->value)
            ->whereIn('delivery_status', [
                DeliveryStatus::Pending->value,
                DeliveryStatus::Failed->value,
            ]);
    }

    /**
     * A label for who wrote this, without inventing an identity.
     *
     * Platforms hand out opaque per-app ids, so the display name they give is
     * all there is. It is not resolved against anything: guessing that two
     * "Sam" accounts are one person is how two customers become one.
     */
    public function authorLabel(): string
    {
        if ($this->is_internal) {
            return ($this->author_name ?? 'A colleague').' (internal note)';
        }

        return match ($this->direction) {
            MessageDirection::Outbound => $this->author_name ?? 'Your team',
            MessageDirection::Inbound => $this->author_name ?? 'Them',
        };
    }
}
