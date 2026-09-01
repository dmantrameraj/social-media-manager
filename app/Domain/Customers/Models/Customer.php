<?php

declare(strict_types=1);

namespace App\Domain\Customers\Models;

use App\Domain\Customers\Enums\CustomerStatus;
use App\Domain\Identity\Models\CustomerPortalUser;
use App\Domain\Identity\Models\User;
use App\Domain\Media\Models\Media;
use App\Domain\Media\Models\MediaFolder;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Policies\CustomerPolicy;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A Customer/Brand workspace -- AGENCY-SCOPED, not a global business record.
 *
 * Two agencies serving the same restaurant hold two independent rows here, so
 * isolation between them is the ordinary tenant rule with nothing bolted on.
 * See docs/03-TENANCY.md §8.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string $slug
 * @property string $timezone
 * @property ?int $logo_media_id
 * @property ?array<string, mixed> $settings
 * @property CustomerStatus $status
 */
#[UseFactory(CustomerFactory::class)]
#[UsePolicy(CustomerPolicy::class)]
class Customer extends Model
{
    use BelongsToTenant, HasUlids, SoftDeletes;

    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'legal_name',
        'slug',
        'industry',
        'website',
        'timezone',
        'contact_name',
        'contact_email',
        'contact_phone',
        'settings',
    ];

    protected $guarded = [
        'id',
        'tenant_id',
        'status',
        'customer_identity_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => CustomerStatus::class,
            'settings' => 'array',
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

    public function logo(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'logo_media_id');
    }

    /**
     * Agency team members assigned to this brand.
     *
     * withPivotValue stamps the denormalised tenant_id on attach and also
     * constrains reads by it. Without it every caller would have to remember
     * to pass tenant_id, and the one that forgot would hit a NOT NULL error at
     * runtime rather than at review.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'customer_user')
            ->withPivotValue('tenant_id', $this->tenant_id)
            ->withTimestamps();
    }

    /** Client logins with access to this brand. */
    public function portalUsers(): BelongsToMany
    {
        return $this->belongsToMany(
            CustomerPortalUser::class,
            'customer_portal_user_customer',
            'customer_id',
            'customer_portal_user_id',
        )
            ->withPivotValue('tenant_id', $this->tenant_id)
            ->withPivot('role')
            ->withTimestamps();
    }

    public function media(): HasMany
    {
        return $this->hasMany(Media::class);
    }

    public function mediaFolders(): HasMany
    {
        return $this->hasMany(MediaFolder::class);
    }

    // ------------------------------------------------------------------ scopes

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', CustomerStatus::Active);
    }

    // ------------------------------------------------------------------- state

    /**
     * Effective timezone for scheduling.
     *
     * The column is NOT NULL and is stamped from the tenant's timezone when
     * the brand is created, so this deliberately does NOT walk the tenant
     * relation -- that would issue a lazy load on a path used once per post
     * per calendar cell.
     */
    public function effectiveTimezone(): string
    {
        return $this->timezone !== '' ? $this->timezone : 'UTC';
    }

    public function requiresClientApproval(): bool
    {
        return (bool) data_get($this->settings, 'approval_required', true);
    }
}
