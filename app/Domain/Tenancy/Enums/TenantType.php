<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Enums;

/**
 * In V1 every tenant is an Agency. Reseller exists so the hierarchy column
 * (tenants.parent_tenant_id) has meaning from day one and a reseller tier can
 * be inserted later without a data migration.
 *
 * No reseller behaviour ships in V1.
 */
enum TenantType: string
{
    case Agency = 'agency';
    case Reseller = 'reseller';

    public function label(): string
    {
        return match ($this) {
            self::Agency => 'Agency',
            self::Reseller => 'Reseller',
        };
    }
}
