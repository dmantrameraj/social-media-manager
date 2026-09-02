<?php

declare(strict_types=1);

namespace App\Domain\Publishing\Models;

use App\Domain\Audit\Enums\ActorType;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A comment on a post, from either side of the agency/client boundary.
 *
 * `is_internal` is the whole point of the model. Agency staff discuss a post
 * candidly -- about the client's brief, their budget, their last round of
 * changes -- and none of that may reach the client. The flag is enforced in
 * the portal's queries rather than filtered in a view, because a view filter
 * is one refactor away from leaking.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $post_id
 * @property int|null $parent_id
 * @property string $author_type
 * @property int|null $author_id
 * @property string $body
 * @property bool $is_internal
 */
final class PostComment extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'post_comments';

    /**
     * Nothing is fillable. `is_internal` in particular must never be reachable
     * from request input: a client-visible comment created with is_internal
     * flipped from a form would be invisible to the person it was written for,
     * and an internal one created without it would be a leak.
     */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'is_internal' => 'boolean',
        ];
    }

    // ---------------------------------------------------------- relationships

    /** @return BelongsTo<Post, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /** @return BelongsTo<self, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<self, $this> */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    // ----------------------------------------------------------------- scopes

    /**
     * Comments a client may read.
     *
     * @param  Builder<self>  $query
     */
    public function scopeClientVisible(Builder $query): Builder
    {
        return $query->where('is_internal', false);
    }

    /** @param  Builder<self>  $query */
    public function scopeInternal(Builder $query): Builder
    {
        return $query->where('is_internal', true);
    }

    // ------------------------------------------------------------------ state

    /**
     * A short label for who wrote this, without leaking a name or email.
     *
     * author_type holds the ActorType discriminator ('user',
     * 'customer_portal_user'), the same value audit_logs and post_approvals
     * store -- not a class name, which would not fit the column and would tie
     * the trail to today's namespaces.
     */
    public function authorLabel(): string
    {
        return ActorType::tryFrom((string) $this->author_type) === ActorType::CustomerPortalUser
            ? 'Client'
            : 'Agency';
    }
}
