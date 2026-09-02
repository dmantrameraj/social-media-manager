<?php

declare(strict_types=1);

namespace App\Domain\AI\DTO;

/**
 * A completed generation.
 *
 * Token counts are recorded even though billing uses flat per-feature credits,
 * so real cost per tenant stays measurable independently of what we charge.
 */
final readonly class AiResponse
{
    /** @param  array<string, mixed>  $raw */
    public function __construct(
        public string $content,
        public int $promptTokens,
        public int $completionTokens,
        public string $model,
        public string $stopReason,
        public int $latencyMs = 0,
        public array $raw = [],
    ) {}

    public function totalTokens(): int
    {
        return $this->promptTokens + $this->completionTokens;
    }

    /**
     * Decode a structured response.
     *
     * @return array<string, mixed>|null null when the model did not return
     *                                   valid JSON, which the caller must
     *                                   handle rather than pass on malformed
     *                                   content to the composer.
     */
    public function json(): ?array
    {
        $decoded = json_decode($this->trimmedJson(), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Models sometimes wrap JSON in a markdown fence despite instructions.
     * Stripping it here beats failing an otherwise good generation.
     */
    private function trimmedJson(): string
    {
        $text = trim($this->content);

        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $text) ?? $text;
        }

        return trim($text);
    }
}
