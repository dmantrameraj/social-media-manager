<?php

declare(strict_types=1);

namespace App\Domain\Social\Contracts;

use App\Domain\Social\Models\SocialAccount;

/** Implemented only where the platform actually allows deleting a published post. */
interface SupportsDeletion
{
    public function deletePost(SocialAccount $account, string $externalId): void;
}
