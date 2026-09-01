<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Access\Concerns\InteractsWithCustomers;
use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantUser;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * An agency team member, or a Super Admin. Guard: `web`.
 *
 * A user is a CROSS-TENANT principal -- the same person may contract for
 * several agencies -- so this model has no tenant_id and does not use
 * BelongsToTenant. Membership lives in tenant_user.
 *
 * Client logins are a different model entirely (CustomerPortalUser, `customer`
 * guard), so a portal session can never resolve to this class.
 * See docs/04-AUTH-RBAC.md §1.
 *
 * @property int $id
 * @property bool $is_super_admin
 * @property UserStatus $status
 */
#[UseFactory(UserFactory::class)]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasRoles, HasUlids, InteractsWithCustomers, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'timezone',
        'locale',
    ];

    /**
     * is_super_admin is guarded, never fillable. It is settable only through
     * an audited console command -- a mass-assignment path to this column is a
     * privilege-escalation vulnerability.
     */
    protected $guarded = [
        'id',
        'is_super_admin',
        'status',
        'email_verified_at',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'status' => UserStatus::class,
            'last_login_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            // Encrypted at rest. Also in $hidden above, and a test asserts
            // they never appear in serialised output.
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted',
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

    // ---------------------------------------------------------------- relations

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_user')
            ->using(TenantUser::class)
            ->withPivot(['status', 'invited_at', 'joined_at'])
            ->withTimestamps();
    }

    /** @return HasMany<TenantUser, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(TenantUser::class);
    }

    /** Brands this user is explicitly assigned to. */
    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(Customer::class, 'customer_user')
            ->withTimestamps();
    }

    // ------------------------------------------------------------------ scopes

    /** @param  Builder<self>  $query */
    public function scopeSuperAdmins(Builder $query): Builder
    {
        return $query->where('is_super_admin', true);
    }

    // ------------------------------------------------------------------- state

    public function isSuperAdmin(): bool
    {
        return $this->is_super_admin === true;
    }

    public function canAuthenticate(): bool
    {
        return $this->status->canAuthenticate();
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    /** Is this user an active member of the given tenant? */
    public function belongsToTenant(Tenant|int $tenant): bool
    {
        $tenantId = $tenant instanceof Tenant ? $tenant->getKey() : $tenant;

        return $this->memberships()
            ->active()
            ->where('tenant_id', $tenantId)
            ->exists();
    }
}
