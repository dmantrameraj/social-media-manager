<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Exceptions;

use RuntimeException;

/**
 * Thrown when the owner role template is absent from config/permissions.php.
 *
 * Provisioning aborts and rolls back rather than creating a tenant whose owner
 * holds no permissions -- an account nobody, including its owner, could
 * administer, and which would look fine until someone tried to use it.
 */
final class MissingOwnerRoleException extends RuntimeException
{
    public function __construct(string $role)
    {
        parent::__construct(
            "Role template [{$role}] is missing from config/permissions.php, so the "
            .'tenant owner could not be granted any permissions. Provisioning aborted.'
        );
    }
}
