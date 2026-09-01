<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Concerns;

use App\Domain\Tenancy\Exceptions\MissingTenantException;
use App\Domain\Tenancy\Exceptions\TenantReassignmentException;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Scopes\TenantScope;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Applied to every tenant-owned model, without exception.
 *
 * Provides three guarantees:
 *   1. Reads are filtered to the active tenant (via TenantScope).
 *   2. Writes are stamped with the active tenant, and fail loudly if there
 *      isn't one.
 *   3. tenant_id can never be changed after creation.
 *
 * An architecture test asserts that every model with a tenant_id column uses
 * this trait -- that test is what stops the guarantee rotting as the schema
 * grows. See docs/03-TENANCY.md §4 and §7.
 *
 * @property int $tenant_id
 * @property-read Tenant $tenant
 *
 * @phpstan-require-extends Model
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            $context = app(TenantContext::class);

            // Stamp from context when the caller did not set it explicitly.
            // An explicit tenant_id is honoured so jobs, seeders and console
            // commands can create records for a specific tenant.
            if ($model->getAttribute('tenant_id') === null && $context->hasTenant()) {
                $model->setAttribute('tenant_id', $context->id());
            }

            if ($model->getAttribute('tenant_id') === null) {
                throw new MissingTenantException($model::class);
            }
        });

        static::updating(function (Model $model): void {
            if ($model->isDirty('tenant_id')) {
                throw new TenantReassignmentException($model::class);
            }
        });
    }

    /**
     * Initialise the trait on each instance.
     *
     * tenant_id is added to $guarded rather than $fillable so it can never be
     * mass-assigned from request input. It is set by the creating hook above,
     * or explicitly via setAttribute -- never by fill().
     */
    public function initializeBelongsToTenant(): void
    {
        $this->mergeGuarded(['tenant_id']);
    }

    /**
     * Never null in practice: tenant_id is NOT NULL with a foreign key, and
     * the trait refuses to create a record without one.
     *
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Remove the tenant filter from a query.
     *
     * Named rather than using withoutGlobalScope() inline so that every bypass
     * greps as `acrossTenants` and can be reviewed. Permitted only in the
     * namespaces listed in config('tenancy.scope_bypass_namespaces'); an
     * architecture test enforces that.
     */
    public function scopeAcrossTenants(Builder $query): Builder
    {
        return $query->withoutGlobalScope(TenantScope::class);
    }

    /**
     * Constrain to a specific tenant regardless of active context.
     *
     * Use in Super Admin tooling where the tenant is chosen deliberately and
     * has already passed a policy check.
     */
    public function scopeForTenant(Builder $query, Tenant|int $tenant): Builder
    {
        return $query
            ->withoutGlobalScope(TenantScope::class)
            ->where(
                $this->qualifyColumn('tenant_id'),
                $tenant instanceof Tenant ? $tenant->getKey() : $tenant,
            );
    }

    /**
     * Does this record belong to the tenant currently in context?
     *
     * Policies call this rather than comparing ids inline, so the comparison
     * is written once.
     */
    public function belongsToCurrentTenant(): bool
    {
        $context = app(TenantContext::class);

        return $context->hasTenant()
            && $this->getAttribute('tenant_id') === $context->id();
    }
}
