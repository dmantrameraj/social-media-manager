<?php

declare(strict_types=1);

namespace App\Domain\Social\Enums;

/**
 * Set by the daily health check, which makes one cheap authenticated read per
 * connection. This is what surfaces a silent revocation that no refresh
 * attempt would reveal until a publish failed.
 */
enum AccountHealth: string
{
    case Healthy = 'healthy';
    case Warning = 'warning';
    case Failed = 'failed';
}
