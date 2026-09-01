<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Scopes;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Constrains every query on a tenant-owned model to the active tenant.
 *
 * This is Layer 2 of five. It stops accidental cross-tenant reads; it does not
 * stop deliberate ones, and it cannot see raw SQL. Policies (Layer 3) and
 * database constraints (Layer 4) cover what this misses.
 *
 * See docs/03-TENANCY.md §4.
 */
final class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        // No tenant context means console, queue bootstrap or the Super Admin
        // surface. We deliberately do NOT filter here -- filtering on a null
        // tenant would silently return an empty set and make every console
        // command look broken. Layers 3 and 4 remain in force.
        if (! $context->hasTenant()) {
            return;
        }

        $builder->where(
            $model->qualifyColumn('tenant_id'),
            $context->id(),
        );
    }
}
