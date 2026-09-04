<?php

declare(strict_types=1);

namespace App\Domain\Publishing\Exceptions;

use App\Domain\Publishing\Enums\PostStatus;
use RuntimeException;

/**
 * The post's words were changed after somebody agreed to them.
 *
 * Distinct from IllegalTransition: nothing was moving. A post past approval
 * has been read and agreed to by a manager or a client, and editing it
 * silently would make that agreement a statement about text that no longer
 * exists.
 */
final class PostNotEditable extends RuntimeException
{
    public static function status(PostStatus $status): self
    {
        return new self(sprintf(
            'A post that is %s cannot be edited. Return it to draft first.',
            mb_strtolower($status->label()),
        ));
    }
}
