<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Models;

use App\Domain\Customers\Models\Customer;
use App\Domain\Engagement\Enums\InboxKind;
use App\Domain\Engagement\Enums\InboxStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Publishing\Models\PostTarget;
use App\Domain\Social\Models\SocialAccount;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\InboxThreadFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One conversation, on one account.
 *
 * The provider owns this, not us. What is stored is a copy that is already
 * slightly out of date -- the other person may have edited or deleted their
 * message since, and anything we send may be refused. Everything here is
 * written to accommodate that rather than pretend otherwise.
 *
 * @property int $tenant_id
 * @property int $customer_id
 * @property int $social_account_id
 * @property string $provider_key
 * @property InboxKind $kind
 * @property string $external_thread_id
 * @property int|null $post_target_id
 * @property string|null $participant_name
 * @property InboxStatus $status
 * @property int|null $assigned_to_user_id
 * @property Carbon|null $last_message_at
 *
 * social_account_id is non-nullable and cascades on delete, so the account is
 * always present -- the same reason PostTarget documents its own relations
 * this way rather than leaving every caller to null-check something that
 * cannot be null.
 *
 * @property-read SocialAccount $socialAccount
 * @property-read Customer|null $customer
 * @property-read User|null $assignee
 */
#[UseFactory(InboxThreadFactory::class)]
class InboxThread extends Model
{
    use BelongsToTenant, HasFactory, HasUlids, SoftDeletes;

    /**
     * Nothing is fillable. Threads are created by the sync from a provider
     * response, and their identity fields -- account, external id, participant
     * -- are never request input.
     *
     * @var list<string>
     */
    protected $fillable = [];

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => InboxKind::class,
            'status' => InboxStatus::class,
            'last_message_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(InboxMessage::class)->orderBy('posted_at');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    /** The post this is about, when it happens to be one of ours. */
    public function target(): BelongsTo
    {
        return $this->belongsTo(PostTarget::class, 'post_target_id');
    }

    /** @param  Builder<self>  $query */
    public function scopeNeedingAttention(Builder $query): Builder
    {
        return $query->where('status', InboxStatus::Open->value);
    }

    /** @param  Builder<self>  $query */
    public function scopeAssignedTo(Builder $query, int $userId): Builder
    {
        return $query->where('assigned_to_user_id', $userId);
    }

    /**
     * Nobody has answered yet.
     *
     * Reads the messages rather than a cached flag, because a flag that says
     * "answered" while the thread shows no reply is worse than no flag: it
     * sends the queue past a customer nobody spoke to.
     */
    public function awaitingFirstReply(): bool
    {
        return ! $this->messages()
            ->where('direction', 'outbound')
            ->where('is_internal', false)
            ->exists();
    }
}
