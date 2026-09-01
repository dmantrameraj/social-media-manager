<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Exceptions;

use RuntimeException;

/**
 * Thrown when code attempts to change tenant_id on an existing record.
 *
 * There is no legitimate reason to move a row between tenants. Every apparent
 * need for it -- transferring a brand, splitting an agency -- is a copy
 * operation with its own audit trail, not an UPDATE.
 */
final class TenantReassignmentException extends RuntimeException
{
    public function __construct(string $model)
    {
        parent::__construct(
            "Attempted to reassign tenant_id on [{$model}]. Records cannot move between "
            .'tenants; copy the data through an explicit, audited service instead.'
        );
    }
}
