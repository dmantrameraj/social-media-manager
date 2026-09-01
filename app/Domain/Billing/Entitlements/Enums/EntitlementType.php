<?php

declare(strict_types=1);

namespace App\Domain\Billing\Entitlements\Enums;

enum EntitlementType: string
{
    /** A numeric ceiling, e.g. brands.max = 25. */
    case Limit = 'limit';

    /** An on/off capability, e.g. analytics.enabled. */
    case Boolean = 'boolean';

    /** No ceiling. Stored explicitly rather than as a magic number. */
    case Unlimited = 'unlimited';
}
