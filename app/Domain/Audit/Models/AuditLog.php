<?php

declare(strict_types=1);

namespace App\Domain\Audit\Models;

use App\Domain\Audit\Enums\ActorType;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only audit trail.
 *
 * NOT a BelongsToTenant model, deliberately: platform-level actions (plan
 * edits, feature flags) belong to no tenant, and the Super Admin viewer needs
 * to read across tenants. Scoping is applied explicitly by the reader instead.
 *
 * @property ?int $tenant_id
 * @property ActorType $actor_type
 * @property ?Carbon $created_at
 */
class AuditLog extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'actor_type' => ActorType::class,
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @param  Builder<self>  $query */
    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    /** @param  Builder<self>  $query */
    public function scopeForEntity(Builder $query, Model $model): Builder
    {
        return $query
            ->where('auditable_type', $model::class)
            ->where('auditable_id', $model->getKey());
    }

    /**
     * Immutability enforced at the model, not only by convention. A history
     * that can be rewritten is not an audit trail.
     */
    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new \RuntimeException('audit_logs is append-only and cannot be modified.');
        });

        static::deleting(static function (): never {
            throw new \RuntimeException('audit_logs rows cannot be deleted.');
        });
    }
}
