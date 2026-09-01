<?php

declare(strict_types=1);

namespace App\Domain\Social\Contracts;

use App\Domain\Social\Models\SocialAccount;

/** Phase 5. Declared now so the capability model is complete. */
interface SupportsAnalytics
{
    /** @return array<string, mixed> */
    public function fetchPostAnalytics(SocialAccount $account, string $externalId): array;
}
