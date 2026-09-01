<?php

declare(strict_types=1);

namespace App\Domain\Publishing\Enums;

enum AttemptOutcome: string
{
    case Success = 'success';
    case RetryableFailure = 'retryable_failure';
    case PermanentFailure = 'permanent_failure';

    /** A worker died before recording an outcome. */
    case Unknown = 'unknown';
}
