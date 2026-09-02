<?php

declare(strict_types=1);

namespace App\Domain\AI\DTO;

/**
 * A vendor-neutral generation request.
 *
 * Feature classes build these; provider adapters translate them. Nothing here
 * names a vendor, which is what lets a second provider be added without
 * touching a single feature.
 */
final readonly class AiRequest
{
    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @param  array<string, mixed>|null  $jsonSchema  when the feature needs structure back
     * @param  array<string, mixed>  $meta  tenant, customer, feature -- for logging only
     */
    public function __construct(
        public string $system,
        public array $messages,
        public ?string $model = null,
        public int $maxTokens = 2048,
        public ?array $jsonSchema = null,
        public array $meta = [],
    ) {}

    public function withModel(string $model): self
    {
        return new self(
            $this->system, $this->messages, $model,
            $this->maxTokens, $this->jsonSchema, $this->meta,
        );
    }

    public function expectsJson(): bool
    {
        return $this->jsonSchema !== null;
    }
}
