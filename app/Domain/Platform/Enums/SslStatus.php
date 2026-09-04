<?php

declare(strict_types=1);

namespace App\Domain\Platform\Enums;

/**
 * Certificate state, TRACKED here and issued elsewhere.
 *
 * Provisioning is a deployment concern -- Caddy, nginx with certbot, or a load
 * balancer -- not application code. Recording what the edge reports lets the
 * screen tell an agency why their domain is not serving yet instead of leaving
 * them to guess.
 */
enum SslStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Certificate pending',
            self::Active => 'Secured',
            self::Failed => 'Certificate failed',
        };
    }
}
