<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Enums;

enum MessageDirection: string
{
    case Inbound = 'inbound';
    case Outbound = 'outbound';
}
