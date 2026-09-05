<?php

declare(strict_types=1);

namespace App\Domain\Publishing\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * What a post used to say.
 *
 * The table shipped in Phase 1 and had no model and no reader until editing
 * existed — which is when it started to matter. An edit overwrites the words a
 * manager or a client agreed to, and without this there is no way to answer
 * "what did they actually approve?" three weeks later, when the post on the
 * feed and the post in the database no longer match.
 *
 * SUPERSEDED STATES ONLY. The current text lives on the post row; each version
 * is the state an edit replaced. So a post written as A, edited to B and then
 * to C has version 1 = A and version 2 = B, and reads back as
 * C (now) → B → A. Storing the current state here too would mean two rows
 * claiming to be authoritative, and they would eventually disagree.
 *
 * Append-only, like post_approvals and login_histories. History that can be
 * edited is not history.
 *
 * @property int $tenant_id
 * @property int $version
 * @property ?string $body
 * @property ?array<string, mixed> $meta
 * @property ?Carbon $created_at
 */
class PostVersion extends Model
{
    use BelongsToTenant, HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'created_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    /** @return BelongsTo<Post, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new RuntimeException('post_versions is append-only.');
        });

        static::deleting(static function (): never {
            throw new RuntimeException('post_versions is append-only.');
        });
    }
}
