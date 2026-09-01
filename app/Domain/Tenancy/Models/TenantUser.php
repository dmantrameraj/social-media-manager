<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Enums\MembershipStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * Tenant membership.
 *
 * This is the authority for "may this user act inside this tenant". Every
 * request re-reads it -- ResolveTenant never trusts the session alone, because
 * a membership can be revoked mid-session.
 *
 * Not a BelongsToTenant model: it is the join that DEFINES tenant access, so
 * scoping it by the active tenant would be circular.
 *
 * @property int $tenant_id
 * @property int $user_id
 * @property MembershipStatus $status
 * @property ?Carbon $invited_at
 * @property ?Carbon $joined_at
 * @property-read Tenant $tenant
 * @property-read User $user
 */
class TenantUser extends Pivot
{
    protected $table = 'tenant_user';

    public $incrementing = true;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'status',
        'invited_by_user_id',
        'invited_at',
        'joined_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => MembershipStatus::class,
            'invited_at' => 'datetime',
            'joined_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', MembershipStatus::Active);
    }

    public function permitsAccess(): bool
    {
        return $this->status->permitsAccess();
    }
}
