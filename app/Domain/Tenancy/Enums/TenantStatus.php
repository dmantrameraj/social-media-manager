<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Enums;

/**
 * Tenant lifecycle states. See docs/03-TENANCY.md §9 for the full matrix of
 * what each state permits.
 */
enum TenantStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case Grace = 'grace';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';

    /**
     * May the tenant use the product at all? Suspended and cancelled tenants
     * can still authenticate -- they are routed to billing so they can pay to
     * return -- but they cannot read or write product data.
     */
    public function permitsProductAccess(): bool
    {
        return match ($this) {
            self::Trialing, self::Active, self::Grace => true,
            self::Suspended, self::Cancelled => false,
        };
    }

    /**
     * May scheduled posts continue to go out?
     *
     * Publishing during grace is a business decision, not a technical one, and
     * defaults to permissive: cutting off a client's scheduled posts because a
     * card expired damages the agency's relationship with their customer.
     */
    public function permitsPublishing(): bool
    {
        return match ($this) {
            self::Trialing, self::Active => true,
            self::Grace => (bool) config('billing.publish_during_grace', true),
            self::Suspended, self::Cancelled => false,
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Cancelled;
    }

    public function label(): string
    {
        return match ($this) {
            self::Trialing => 'Trialing',
            self::Active => 'Active',
            self::Grace => 'Grace period',
            self::Suspended => 'Suspended',
            self::Cancelled => 'Cancelled',
        };
    }
}
