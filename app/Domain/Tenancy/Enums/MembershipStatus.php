<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Enums;

enum MembershipStatus: string
{
    case Invited = 'invited';
    case Active = 'active';
    case Suspended = 'suspended';

    /**
     * Only an active membership resolves tenant context. An invited member has
     * not accepted yet; a suspended one has been switched off by an admin.
     */
    public function permitsAccess(): bool
    {
        return $this === self::Active;
    }
}
