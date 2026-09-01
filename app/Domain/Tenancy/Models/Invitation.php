<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A pending team invitation.
 *
 * The raw token is emailed once and never stored -- only its SHA-256 hash
 * lives here, so a database read cannot be turned into account access.
 *
 * @property int $tenant_id
 * @property string $email
 * @property ?Carbon $expires_at
 * @property ?Carbon $accepted_at
 * @property ?Carbon $revoked_at
 * @property ?array<int, int> $customer_ids
 */
class Invitation extends Model
{
    use BelongsToTenant, HasFactory, HasUlids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'customer_ids' => 'array',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    /** @param  Builder<self>  $query */
    public function scopePending(Builder $query): Builder
    {
        return $query
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null
            && $this->revoked_at === null
            && $this->expires_at?->isFuture() === true;
    }

    /**
     * Why an invitation cannot be used. Returned as a reason rather than a
     * bare false so the UI can say "this invitation expired" instead of the
     * unhelpful "invalid link".
     */
    public function unusableReason(): ?string
    {
        return match (true) {
            $this->accepted_at !== null => 'This invitation has already been accepted.',
            $this->revoked_at !== null => 'This invitation was revoked.',
            $this->expires_at?->isPast() === true => 'This invitation has expired.',
            default => null,
        };
    }
}
