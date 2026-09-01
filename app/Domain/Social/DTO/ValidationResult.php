<?php

declare(strict_types=1);

namespace App\Domain\Social\DTO;

/**
 * The outcome of validating a payload against one provider's rules.
 *
 * Collects ALL failures rather than stopping at the first, so the composer can
 * show everything wrong at once instead of one problem per submit.
 */
final readonly class ValidationResult
{
    /** @param  list<ValidationError>  $errors */
    private function __construct(public bool $passed, public array $errors = []) {}

    public static function pass(): self
    {
        return new self(true);
    }

    /** @param  list<ValidationError>  $errors */
    public static function fail(array $errors): self
    {
        return new self(false, $errors);
    }

    public function failed(): bool
    {
        return ! $this->passed;
    }

    /** @return list<string> */
    public function messages(): array
    {
        return array_map(static fn (ValidationError $e): string => $e->message, $this->errors);
    }

    public function firstMessage(): ?string
    {
        return $this->errors[0]->message ?? null;
    }
}
