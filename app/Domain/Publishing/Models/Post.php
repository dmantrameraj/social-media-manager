<?php

declare(strict_types=1);

namespace App\Domain\Publishing\Models;

use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Media\Models\Media;
use App\Domain\Publishing\Enums\PostStatus;
use App\Domain\Publishing\Enums\TargetStatus;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\PostFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Master content for one brand.
 *
 * The post-level status is DERIVED for display; per-target rows hold the truth
 * about what actually published. See docs/06-PUBLISHING-ENGINE.md §1.
 *
 * @property int $tenant_id
 * @property PostStatus $status
 * @property ?Carbon $scheduled_at
 */
#[UseFactory(PostFactory::class)]
class Post extends Model
{
    use BelongsToTenant, HasFactory, HasUlids, SoftDeletes;

    protected $fillable = ['title', 'body', 'link_url', 'first_comment', 'content_type'];

    /** Status is owned by PostStatusMachine and never assigned directly. */
    protected $guarded = ['id', 'tenant_id', 'status', 'customer_id'];

    protected function casts(): array
    {
        return [
            'status' => PostStatus::class,
            'scheduled_at' => 'datetime',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'published_at' => 'datetime',
            'approval_required' => 'boolean',
            'meta' => 'array',
        ];
    }

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return HasMany<PostTarget, $this> */
    public function targets(): HasMany
    {
        return $this->hasMany(PostTarget::class);
    }

    /** @return HasMany<PostApproval, $this> */
    public function approvals(): HasMany
    {
        return $this->hasMany(PostApproval::class);
    }

    /**
     * Media attached to this post, in the order the agency arranged it.
     *
     * Ordered in the relation rather than at each call site: a carousel shown
     * in a different order than the client approved is a real complaint, and
     * sort_order is the only thing that records the intent.
     *
     * @return BelongsToMany<Media, $this>
     */
    public function media(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'post_media')
            ->withPivot(['sort_order', 'role', 'post_target_id'])
            ->orderBy('post_media.sort_order');
    }

    /** @param  Builder<self>  $query */
    public function scopeVisibleToPortal(Builder $query): Builder
    {
        return $query->whereIn('status', array_values(array_map(
            static fn (PostStatus $s): string => $s->value,
            array_filter(PostStatus::cases(), static fn (PostStatus $s): bool => $s->isVisibleToPortal()),
        )));
    }

    /**
     * Post status derived from its targets.
     *
     * There is deliberately no path that marks a post wholly failed because
     * one provider failed -- that is the single most important rule in the
     * engine.
     */
    public function deriveStatusFromTargets(): PostStatus
    {
        $statuses = $this->targets->pluck('status');

        if ($statuses->isEmpty()) {
            return $this->status;
        }

        $published = $statuses->filter(fn (TargetStatus $s): bool => $s->isSuccess())->count();
        $failed = $statuses->filter(fn (TargetStatus $s): bool => $s === TargetStatus::Failed)->count();
        $inFlight = $statuses->filter(
            fn (TargetStatus $s): bool => in_array($s, [
                TargetStatus::Pending, TargetStatus::Scheduled,
                TargetStatus::Processing, TargetStatus::NeedsVerification,
            ], true)
        )->count();

        return match (true) {
            $inFlight > 0 && $published > 0 => PostStatus::Processing,
            $inFlight > 0 => PostStatus::Processing,
            $published > 0 && $failed > 0 => PostStatus::PartiallyPublished,
            $published > 0 => PostStatus::Published,
            $failed > 0 => PostStatus::Failed,
            default => $this->status,
        };
    }
}
