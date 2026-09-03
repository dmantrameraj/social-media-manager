<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Models;

use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Enums\TenantType;
use Database\Factories\TenantFactory;
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
 * The unit of isolation and billing. In V1 always an agency.
 *
 * Tenant itself does NOT use BelongsToTenant -- it is the root of the
 * hierarchy, not a member of it.
 *
 * @property int $id
 * @property string $ulid
 * @property string $name
 * @property string $slug
 * @property string $timezone
 * @property TenantType $type
 * @property TenantStatus $status
 * @property ?int $parent_tenant_id
 * @property ?int $owner_user_id
 * @property ?array<string, mixed> $settings
 * @property ?Carbon $trial_ends_at
 * @property ?Carbon $suspended_at
 * @property ?Carbon $cancelled_at
 * @property ?Carbon $purge_after
 * @property ?Carbon $purged_at
 */
#[UseFactory(TenantFactory::class)]
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    use HasUlids, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'type',
        'timezone',
        'locale',
        'currency',
    ];

    /**
     * status, trial_ends_at and the retention clock are lifecycle-owned: they
     * are set by TenantLifecycleService, never by mass assignment from a form.
     */
    protected $guarded = [
        'id',
        'status',
        'parent_tenant_id',
        'owner_user_id',
        'trial_ends_at',
        'suspended_at',
        'cancelled_at',
        'purge_after',
        'purged_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => TenantType::class,
            'status' => TenantStatus::class,
            'settings' => 'array',
            'trial_ends_at' => 'datetime',
            'suspended_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'purge_after' => 'datetime',
            'purged_at' => 'datetime',
        ];
    }

    /**
     * ULIDs are a secondary public identifier, not the primary key. The PK
     * stays an auto-increment bigint so InnoDB clusters well.
     */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    // ---------------------------------------------------------------- relations

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /** Reseller hierarchy. Unused in V1. */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_tenant_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_tenant_id');
    }

    /** @return BelongsToMany<User, $this, TenantUser, 'pivot'> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tenant_user')
            ->using(TenantUser::class)
            ->withPivot(['status', 'invited_at', 'joined_at'])
            ->withTimestamps();
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(TenantUser::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    // ------------------------------------------------------------------ scopes

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            TenantStatus::Trialing->value,
            TenantStatus::Active->value,
        ]);
    }

    /** @param  Builder<self>  $query */
    public function scopeDuePurge(Builder $query, ?Carbon $at = null): Builder
    {
        return $query
            ->whereNotNull('purge_after')
            ->where('purge_after', '<=', $at ?? now());
    }

    // ------------------------------------------------------------------- state

    public function isOnTrial(): bool
    {
        return $this->status === TenantStatus::Trialing
            && $this->trial_ends_at?->isFuture() === true;
    }

    public function permitsProductAccess(): bool
    {
        return $this->status->permitsProductAccess();
    }

    public function permitsPublishing(): bool
    {
        return $this->status->permitsPublishing();
    }
}
