<?php

declare(strict_types=1);

namespace App\Domain\Publishing\Exceptions;

use App\Domain\Publishing\Enums\TargetStatus;
use RuntimeException;

/**
 * A target was asked to publish again when that was not safe.
 *
 * Separate from IllegalTransition because the question is not about the post's
 * workflow: it is about whether sending the same content a second time could
 * put two copies on somebody's feed.
 */
final class CannotRetry extends RuntimeException
{
    public static function status(TargetStatus $status): self
    {
        return new self(match ($status) {
            TargetStatus::Published => 'That destination has already published.',

            /*
             | The honest answer, rather than a refusal with no route forward.
             | NeedsVerification means a worker died mid-publish and nobody
             | knows whether the post went out; retrying blind is how a client
             | ends up with two copies.
             */
            TargetStatus::NeedsVerification => 'We do not yet know whether that one published. '
                .'Check the account before sending it again.',

            TargetStatus::Processing => 'That destination is publishing right now.',

            TargetStatus::PausedReconnect => 'That account needs reconnecting first.',
            TargetStatus::PausedBilling => 'That is paused until billing is resolved.',

            default => 'Only a failed destination can be sent again.',
        });
    }
}
