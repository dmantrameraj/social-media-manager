<?php

declare(strict_types=1);

namespace App\Domain\Publishing\DTO;

/**
 * One row's outcome.
 *
 * `line` is the line number in the uploaded file, header included -- what the
 * user will see when they open the file to fix it. Counting data rows from one
 * would send them to the wrong line every time.
 */
final class ImportRow
{
    public function __construct(
        public readonly int $line,
        public readonly bool $created,
        public readonly string $message,
        public readonly ?int $postId = null,
        public readonly ?string $title = null,
    ) {}

    public static function ok(int $line, int $postId, ?string $title): self
    {
        return new self($line, true, 'Imported as a draft.', $postId, $title);
    }

    public static function skipped(int $line, string $why, ?string $title = null): self
    {
        return new self($line, false, $why, null, $title);
    }
}
