<?php

declare(strict_types=1);

namespace App\Domain\Social\Enums;

enum SocialAccountType: string
{
    case Page = 'page';
    case IgBusiness = 'ig_business';
    case Profile = 'profile';
    case Channel = 'channel';
    case Organization = 'organization';

    public function label(): string
    {
        return match ($this) {
            self::Page => 'Facebook Page',
            self::IgBusiness => 'Instagram Business',
            self::Profile => 'Profile',
            self::Channel => 'YouTube Channel',
            self::Organization => 'LinkedIn Organisation',
        };
    }
}
