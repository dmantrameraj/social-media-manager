<?php

declare(strict_types=1);

namespace App\Domain\AI\Contracts;

use App\Domain\AI\DTO\AiRequest;
use App\Domain\AI\DTO\AiResponse;

/**
 * One AI capability: caption, hashtags, ideas, rewrite.
 *
 * Features know nothing about which vendor runs them, and nothing about
 * credits -- GenerateContentService handles both.
 */
interface AiFeatureInterface
{
    /** Matches a key in config('ai.costs'). */
    public function key(): string;

    /**
     * Brand Brain fields this feature needs.
     *
     * Kept minimal on purpose: unused context costs credits and dilutes the
     * output.
     *
     * @return list<string>
     */
    public function requiredBrainSections(): array;

    /**
     * @param  array<string, mixed>  $input
     */
    public function buildRequest(array $input, string $brandContext): AiRequest;

    /**
     * Turn a raw response into the feature's result shape.
     *
     * @return array<string, mixed>
     */
    public function parseResponse(AiResponse $response): array;
}
