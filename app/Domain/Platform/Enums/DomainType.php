<?php

declare(strict_types=1);

namespace App\Domain\Platform\Enums;

/**
 * How an agency reaches their portal.
 *
 * A subdomain we issue is verified by construction -- we control the zone. A
 * custom domain is somebody else's DNS, so it has to prove itself before it
 * resolves to anything.
 */
enum DomainType: string
{
    case Subdomain = 'subdomain';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Subdomain => 'Subdomain',
            self::Custom => 'Custom domain',
        };
    }

    /** Does this need a DNS record proving the agency controls it? */
    public function requiresVerification(): bool
    {
        return $this === self::Custom;
    }
}
