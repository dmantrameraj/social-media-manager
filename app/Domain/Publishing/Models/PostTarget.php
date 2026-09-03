<?php

declare(strict_types=1);

namespace App\Domain\Publishing\Models;

use App\Domain\Publishing\Enums\TargetStatus;
use App\Domain\Social\Models\SocialAccount;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\PostTargetFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One publication: this post, to this account.
 *
 * Owns its own status, schedule, retry counter and external id, because each
 * destination genuinely succeeds or fails alone.
 *
 * @property int $tenant_id
 * @property TargetStatus $status
 * @property ?Carbon $scheduled_at
 * @property ?Carbon $locked_at
 * @property ?Carbon $next_attempt_at
 * @property ?array<string, mixed> $meta Per-platform fields set at scheduling
 * @property ?array<string, mixed> $meta_override Per-platform fields overridden on this target
 * @property-read Post $post
 * @property-read SocialAccount $socialAccount
 */
#[UseFactory(PostTargetFactory::class)]
class PostTarget extends Model
{
    use BelongsToTenant, HasFactory, HasUlids;

    /** Everything here is engine-owned; nothing comes from request input. */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'status' => TargetStatus::class,
            'meta_override' => 'array',
            'meta' => 'array',
            'scheduled_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'published_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'locked_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    /** @return BelongsTo<Post, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /** @return BelongsTo<SocialAccount, $this> */
    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    /** @return HasMany<PublicationAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(PublicationAttempt::class);
    }

    /**
     * Targets whose time has come.
     *
     * @param  Builder<self>  $query
     */
    public function scopeDue(Builder $query, ?Carbon $at = null): Builder
    {
        $now = $at ?? now();

        return $query
            ->where('status', TargetStatus::Scheduled->value)
            ->where('scheduled_at', '<=', $now)
            ->where(function (Builder $q) use ($now): void {
                $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now);
            });
    }

    /**
     * Targets stuck in processing past the lock TTL -- a worker died.
     *
     * @param  Builder<self>  $query
     */
    public function scopeStale(Builder $query, ?int $ttlSeconds = null): Builder
    {
        $ttl = $ttlSeconds ?? (int) config('publishing.lock_ttl', 900);

        return $query
            ->where('status', TargetStatus::Processing->value)
            ->whereNotNull('locked_at')
            ->where('locked_at', '<', now()->subSeconds($ttl));
    }

    /** The body actually published: an override, or the master. */
    public function effectiveBody(): string
    {
        return $this->body_override ?? (string) $this->post->body;
    }

    public function hasExternalPost(): bool
    {
        return $this->external_post_id !== null && $this->external_post_id !== '';
    }

    public function attemptsRemaining(): int
    {
        return max(0, (int) $this->max_attempts - (int) $this->attempts);
    }
}
