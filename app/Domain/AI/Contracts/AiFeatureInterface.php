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

    /** Human name for the picker. */
    public function label(): string;

    /** One line saying what this produces, shown under the name. */
    public function description(): string;

    /**
     * The inputs this feature reads, declared by the feature itself.
     *
     * Declared here rather than described in config so there is one source of
     * truth. A form built from a separate list drifts the moment a feature
     * starts reading a key nobody added to it, and the symptom -- a field
     * silently ignored -- looks like a model problem rather than a wiring one.
     *
     * `type` is a form hint only: text, textarea, number, date or select.
     * `options` applies to select. Everything is optional unless `required`,
     * because every feature already defaults a missing key.
     *
     * @return list<array{
     *     name: string,
     *     label: string,
     *     type: string,
     *     required?: bool,
     *     help?: string,
     *     options?: array<string, string>,
     *     max?: int,
     *     min?: int
     * }>
     */
    public function inputFields(): array;

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
