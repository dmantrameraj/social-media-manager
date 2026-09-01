<?php

declare(strict_types=1);

namespace App\Domain\Social\Contracts;

use App\Domain\Social\Models\SocialAccount;

/**
 * Posting a follow-up comment on our own post -- the usual way agencies keep
 * hashtags out of the caption body.
 */
interface SupportsFirstComment
{
    public function publishFirstComment(SocialAccount $account, string $externalId, string $body): void;
}
