<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Exceptions;

use RuntimeException;

/**
 * Thrown when tenant context is required but has not been established.
 *
 * This is deliberately loud. The alternative -- silently returning null and
 * letting a query run unscoped -- is the exact failure mode that produces a
 * cross-tenant leak.
 */
final class TenantNotResolved extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'No tenant is resolved for the current context. Code running outside a '
            .'tenant-scoped request must establish context explicitly before touching '
            .'tenant-owned models.'
        );
    }
}
