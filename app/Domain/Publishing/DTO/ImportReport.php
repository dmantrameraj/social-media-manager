<?php

declare(strict_types=1);

namespace App\Domain\Publishing\DTO;

/**
 * What an import did, row by row.
 *
 * The per-row detail is the product, not a diagnostic. An import that reports
 * "37 of 40 imported" and stops there leaves somebody diffing two spreadsheets
 * to find the three, which is worse than not offering the feature.
 */
final class ImportReport
{
    /** @param list<ImportRow> $rows */
    public function __construct(
        public readonly array $rows = [],
        /**
         * A problem with the FILE rather than with a row -- an unreadable
         * upload, a missing required column. Nothing is imported when this is
         * set, because a file whose shape we misread would import the wrong
         * columns silently.
         */
        public readonly ?string $fatal = null,
    ) {}

    public static function fatal(string $message): self
    {
        return new self([], $message);
    }

    public function created(): int
    {
        return count(array_filter($this->rows, fn (ImportRow $row): bool => $row->created));
    }

    public function failed(): int
    {
        return count($this->rows) - $this->created();
    }

    /** @return list<ImportRow> */
    public function failures(): array
    {
        return array_values(array_filter($this->rows, fn (ImportRow $row): bool => ! $row->created));
    }

    public function summary(): string
    {
        if ($this->fatal !== null) {
            return $this->fatal;
        }

        $failed = $this->failed();

        return $failed === 0
            ? sprintf('Imported %d posts as drafts.', $this->created())
            : sprintf('Imported %d posts as drafts. %d rows were skipped.', $this->created(), $failed);
    }
}
