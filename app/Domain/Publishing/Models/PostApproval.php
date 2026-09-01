<?php

declare(strict_types=1);

namespace App\Domain\Publishing\Models;

use App\Domain\Audit\Enums\ActorType;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable approval history.
 *
 * Every workflow transition writes one, so "who approved this, when, and what
 * did they say" is always answerable -- the question agencies actually get
 * asked when a client disputes a post.
 *
 * @property ActorType $actor_type
 */
class PostApproval extends Model
{
    use BelongsToTenant, HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'actor_type' => ActorType::class,
            'created_at' => 'datetime',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
