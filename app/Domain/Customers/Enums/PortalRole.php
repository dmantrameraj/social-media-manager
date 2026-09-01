<?php

declare(strict_types=1);

namespace App\Domain\Customers\Enums;

/**
 * A portal user's role is assigned per brand, in
 * customer_portal_user_customer.role -- one person may approve for one brand
 * and only view another.
 */
enum PortalRole: string
{
    case Approver = 'approver';
    case Viewer = 'viewer';

    public function canApprove(): bool
    {
        return $this === self::Approver;
    }

    public function label(): string
    {
        return match ($this) {
            self::Approver => 'Approver',
            self::Viewer => 'Viewer',
        };
    }
}
