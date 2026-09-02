<?php

declare(strict_types=1);

namespace App\Domain\AI\Features;

use App\Domain\AI\Contracts\AiFeatureInterface;
use App\Domain\AI\DTO\AiRequest;
use App\Domain\AI\Features\Concerns\TransformsText;

/**
 * Translates copy, keeping brand names and hashtags intact.
 */
final class TranslateFeature implements AiFeatureInterface
{
    use TransformsText;

    public function key(): string
    {
        return 'translate';
    }

    public function requiredBrainSections(): array
    {
        return ['brand_tone', 'languages', 'primary_language'];
    }

    public function buildRequest(array $input, string $brandContext): AiRequest
    {
        return $this->buildTransformRequest($input, $brandContext);
    }

    protected function instruction(array $input): string
    {
        // Language is user input, so it is length-capped and stripped of
        // newlines before it reaches the system prompt.
        $language = trim((string) ($input['target_language'] ?? ''));
        $language = preg_replace('/[\r\n]+/', ' ', $language) ?? '';
        $language = mb_substr($language, 0, 60);

        if ($language === '') {
            return 'Translate the text below into the brand\'s primary language.';
        }

        return "Translate the text below into {$language}. "
            .'Keep brand names, product names, hashtags and @mentions unchanged.';
    }
}
