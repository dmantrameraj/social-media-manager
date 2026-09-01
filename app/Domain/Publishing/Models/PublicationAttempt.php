<?php

declare(strict_types=1);

namespace App\Domain\Publishing\Models;

use App\Domain\Publishing\Enums\AttemptOutcome;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable record of one publish attempt.
 *
 * response_snapshot is redacted before write and is gated behind posts.retry;
 * it is never shown to portal users.
 *
 * @property AttemptOutcome|null $outcome
 */
class PublicationAttempt extends Model
{
    use BelongsToTenant, HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'outcome' => AttemptOutcome::class,
            'response_snapshot' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(PostTarget::class, 'post_target_id');
    }

    public function isOpen(): bool
    {
        return $this->finished_at === null;
    }
}
