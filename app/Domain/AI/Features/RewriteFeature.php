<?php

declare(strict_types=1);

namespace App\Domain\AI\Features;

use App\Domain\AI\Contracts\AiFeatureInterface;
use App\Domain\AI\DTO\AiRequest;
use App\Domain\AI\Features\Concerns\TransformsText;

/**
 * Rewrites existing copy while keeping its meaning.
 */
final class RewriteFeature implements AiFeatureInterface
{
    use TransformsText;

    public function key(): string
    {
        return 'rewrite';
    }

    public function label(): string
    {
        return 'Rewrite';
    }

    public function description(): string
    {
        return 'Rewrites existing copy toward a goal.';
    }

    public function inputFields(): array
    {
        return [
            ['name' => 'text', 'label' => 'Text', 'type' => 'textarea', 'required' => true],
            ['name' => 'goal', 'label' => 'Goal', 'type' => 'text', 'help' => 'Shorter, clearer, more persuasive.'],
        ];
    }

    public function requiredBrainSections(): array
    {
        return ['brand_tone', 'brand_voice_notes', 'preferred_keywords', 'primary_language'];
    }

    public function buildRequest(array $input, string $brandContext): AiRequest
    {
        return $this->buildTransformRequest($input, $brandContext);
    }

    protected function instruction(array $input): string
    {
        $goal = trim((string) ($input['goal'] ?? ''));

        return $goal !== ''
            ? "Rewrite the text below so that it is {$goal}, in the brand's voice."
            : "Rewrite the text below in the brand's voice, keeping it roughly the same length.";
    }
}
