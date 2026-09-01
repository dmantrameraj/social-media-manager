<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Exceptions;

use RuntimeException;

/**
 * Thrown when a tenant-owned model is created with no tenant_id and no tenant
 * context to infer one from.
 *
 * Failing closed here is the point: a model that reached the database with a
 * null or borrowed tenant_id is unrecoverable data corruption, whereas a thrown
 * exception is a bug report.
 */
final class MissingTenantException extends RuntimeException
{
    public function __construct(string $model)
    {
        parent::__construct(
            "Cannot create [{$model}] without a tenant_id. Either establish tenant "
            .'context first, or set tenant_id explicitly when creating outside a request.'
        );
    }
}
