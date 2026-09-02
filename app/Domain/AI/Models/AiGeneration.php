<?php

declare(strict_types=1);

namespace App\Domain\AI\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One generation attempt, successful or not.
 *
 * Answers the three questions that actually matter: what does AI cost per
 * tenant, which features get used, and which ones fail. Token counts are
 * recorded even though billing uses flat credits, so real cost stays
 * measurable independently of what we charge.
 *
 * @property int $tenant_id
 */
class AiGeneration extends Model
{
    use BelongsToTenant, HasFactory, HasUlids;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'request_snapshot' => 'array',
            'response_snapshot' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    /**
     * Snapshots older than the retention window.
     *
     * @param  Builder<self>  $query
     */
    public function scopeSnapshotsExpired(Builder $query): Builder
    {
        $days = (int) config('ai.snapshot_retention_days', 30);

        return $query
            ->where('created_at', '<', now()->subDays($days))
            ->where(function (Builder $q): void {
                $q->whereNotNull('request_snapshot')->orWhereNotNull('response_snapshot');
            });
    }

    public function succeeded(): bool
    {
        return $this->status === 'succeeded';
    }
}
