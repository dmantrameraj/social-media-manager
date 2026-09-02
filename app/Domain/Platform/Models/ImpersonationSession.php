<?php

declare(strict_types=1);

namespace App\Domain\Platform\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One period during which a Super Admin acted as another principal.
 *
 * Deliberately NOT tenant-scoped: the row belongs to the platform, not to the
 * agency being supported. `tenant_id` records which agency was entered, so the
 * trail can be read from either direction.
 *
 * @property int $id
 * @property int $super_admin_user_id
 * @property string $target_type
 * @property int $target_id
 * @property int|null $tenant_id
 * @property string $reason
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
 */
final class ImpersonationSession extends Model
{
    protected $table = 'impersonation_sessions';

    /**
     * Every column is set by ImpersonationService through forceCreate. Nothing
     * here is fillable, because a mass-assignment path into
     * `super_admin_user_id` would let a request choose who it is acting as.
     */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    // ---------------------------------------------------------- relationships

    /** @return BelongsTo<User, $this> */
    public function superAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'super_admin_user_id');
    }

    /** @return MorphTo<Model, $this> */
    public function target(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'target_type', 'target_id');
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    // ----------------------------------------------------------------- scopes

    /** @param  Builder<self>  $query */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }

    /**
     * Sessions that have outlived the configured ceiling and must be closed.
     *
     * @param  Builder<self>  $query
     */
    public function scopeExpired(Builder $query, ?Carbon $at = null): Builder
    {
        $at ??= now();

        return $query->whereNull('ended_at')
            ->where('started_at', '<=', $at->copy()->subMinutes(self::timeoutMinutes()));
    }

    // ------------------------------------------------------------------ state

    public function isOpen(): bool
    {
        return $this->ended_at === null;
    }

    public static function timeoutMinutes(): int
    {
        return (int) config('platform.impersonation.timeout_minutes', 60);
    }

    public function expiresAt(): Carbon
    {
        return $this->started_at->copy()->addMinutes(self::timeoutMinutes());
    }

    public function hasExpired(?Carbon $at = null): bool
    {
        return $this->isOpen() && $this->expiresAt()->lessThanOrEqualTo($at ?? now());
    }

    public function elapsedMinutes(?Carbon $at = null): int
    {
        return (int) $this->started_at->diffInMinutes($at ?? now());
    }
}
