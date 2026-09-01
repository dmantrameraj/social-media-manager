<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Customers\Enums\PortalRole;
use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Policies\CustomerPortalUserPolicy;
use Database\Factories\CustomerPortalUserFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Spatie\Permission\Traits\HasRoles;

/**
 * A client login. Guard: `customer`.
 *
 * Deliberately a separate table and model from User. Because the `customer`
 * guard resolves through a different provider, auth('web')->user() can never
 * return a portal user -- so a bug in role resolution cannot escalate a client
 * into the agency dashboard. See docs/04-AUTH-RBAC.md §1.
 *
 * Tenant-scoped: the same person working with two agencies has two logins.
 *
 * @property int $id
 * @property int $tenant_id
 * @property UserStatus $status
 */
#[UseFactory(CustomerPortalUserFactory::class)]
#[UsePolicy(CustomerPortalUserPolicy::class)]
class CustomerPortalUser extends Authenticatable
{
    use BelongsToTenant, HasRoles, HasUlids, Notifiable, SoftDeletes;

    /** @use HasFactory<CustomerPortalUserFactory> */
    use HasFactory;

    /** Roles and permissions resolve on the customer guard, not web. */
    protected string $guard_name = 'customer';

    protected $fillable = [
        'name',
        'email',
        'password',
        'timezone',
        'locale',
    ];

    protected $guarded = [
        'id',
        'tenant_id',
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
            'status' => UserStatus::class,
            'last_login_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
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

    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(
            Customer::class,
            'customer_portal_user_customer',
            'customer_portal_user_id',
            'customer_id',
        )->withPivot('role')->withTimestamps();
    }

    // ------------------------------------------------------------------- state

    /** @return Collection<int, int> */
    public function assignedCustomerIds(): Collection
    {
        return $this->customers()
            ->pluck('customers.id')
            ->map(static fn (mixed $id): int => (int) $id);
    }

    public function canAccessCustomer(Customer|int|null $customer): bool
    {
        if ($customer === null) {
            return false;
        }

        $customerId = $customer instanceof Customer ? $customer->getKey() : $customer;

        return $this->assignedCustomerIds()->contains($customerId);
    }

    /**
     * Approval rights are per brand: the same person may approve for one
     * client and only view another.
     */
    public function canApproveFor(Customer|int $customer): bool
    {
        $customerId = $customer instanceof Customer ? $customer->getKey() : $customer;

        $pivot = $this->customers()
            ->where('customers.id', $customerId)
            ->first()?->pivot;

        return $pivot !== null
            && PortalRole::from($pivot->role)->canApprove();
    }

    public function canAuthenticate(): bool
    {
        return $this->status->canAuthenticate();
    }
}
