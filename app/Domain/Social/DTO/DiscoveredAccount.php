<?php

declare(strict_types=1);

namespace App\Domain\Social\DTO;

use App\Domain\Social\Enums\SocialAccountType;

/**
 * A publishable destination found behind one OAuth grant.
 *
 * One Meta connection commonly yields several Pages plus the Instagram
 * accounts linked to them, which is exactly why connections and accounts are
 * separate tables.
 */
final readonly class DiscoveredAccount
{
    /**
     * @param  list<string>  $scopes
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $externalId,
        public string $name,
        public SocialAccountType $type,
        public ?string $username = null,
        public ?string $avatarUrl = null,
        /**
         * Facebook Page tokens differ from the user token that discovered
         * them, and are what publishing actually uses.
         */
        public ?string $pageAccessToken = null,
        public array $scopes = [],
        public array $meta = [],
    ) {}
}
