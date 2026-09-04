<?php

declare(strict_types=1);

namespace App\Domain\AI\Features;

use App\Domain\AI\Contracts\AiFeatureInterface;
use App\Domain\AI\DTO\AiRequest;
use App\Domain\AI\Features\Concerns\TransformsText;

/**
 * Shifts the tone of existing copy without changing what it says.
 */
final class ToneFeature implements AiFeatureInterface
{
    public const TONES = [
        'professional', 'friendly', 'playful', 'authoritative',
        'empathetic', 'urgent', 'inspirational', 'conversational',
    ];

    use TransformsText;

    public function key(): string
    {
        return 'tone';
    }

    public function label(): string
    {
        return 'Change tone';
    }

    public function description(): string
    {
        return 'Rewrites copy in a different tone.';
    }

    public function inputFields(): array
    {
        return [
            ['name' => 'text', 'label' => 'Text', 'type' => 'textarea', 'required' => true],
            ['name' => 'tone', 'label' => 'Tone', 'type' => 'text', 'help' => 'Warmer, more formal, playful.'],
        ];
    }

    public function requiredBrainSections(): array
    {
        return ['brand_tone', 'brand_voice_notes', 'primary_language'];
    }

    public function buildRequest(array $input, string $brandContext): AiRequest
    {
        return $this->buildTransformRequest($input, $brandContext);
    }

    protected function instruction(array $input): string
    {
        $tone = trim((string) ($input['tone'] ?? ''));

        // An unrecognised tone falls back to the brand's own rather than being
        // passed through verbatim -- a free-text tone is user input heading
        // into a system prompt.
        if ($tone === '' || ! in_array($tone, self::TONES, true)) {
            return "Rewrite the text below in the brand's own tone.";
        }

        return "Rewrite the text below so its tone is {$tone}, while still fitting the brand.";
    }
}
