<?php

declare(strict_types=1);

namespace App\Domain\Audit\Enums;

/**
 * Audit and approval records need an actor that may come from either guard, or
 * from no guard at all (scheduled jobs, lifecycle transitions).
 *
 * A string discriminator rather than an Eloquent morph: the audit log must
 * survive deletion of the actor, and must never trigger a model load while
 * writing.
 */
enum ActorType: string
{
    case User = 'user';
    case CustomerPortalUser = 'customer_portal_user';
    case System = 'system';

    public function isHuman(): bool
    {
        return $this !== self::System;
    }

    public function label(): string
    {
        return match ($this) {
            self::User => 'Team member',
            self::CustomerPortalUser => 'Client',
            self::System => 'System',
        };
    }
}
