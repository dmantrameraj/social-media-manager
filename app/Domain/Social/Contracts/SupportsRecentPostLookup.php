<?php

declare(strict_types=1);

namespace App\Domain\Social\Contracts;

use App\Domain\Social\Models\SocialAccount;

/**
 * Listing recent posts for an account.
 *
 * This is what makes duplicate recovery possible: after a crash mid-publish,
 * the engine asks whether the post actually landed instead of blindly retrying
 * and double-posting. See docs/06-PUBLISHING-ENGINE.md §7.
 */
interface SupportsRecentPostLookup
{
    /**
     * Find a post matching this idempotency fingerprint, if one exists.
     *
     * @return string|null the external post id
     */
    public function findRecentPostByFingerprint(SocialAccount $account, string $fingerprint): ?string;
}
