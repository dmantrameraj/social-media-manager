<?php

declare(strict_types=1);

namespace App\Domain\AI\Features;

use App\Domain\AI\Contracts\AiFeatureInterface;
use App\Domain\AI\DTO\AiRequest;
use App\Domain\AI\DTO\AiResponse;

/**
 * A YouTube description, with the first two lines carrying the weight, since
 * that is all most viewers see before "show more".
 */
final class YoutubeDescriptionFeature implements AiFeatureInterface
{
    public function key(): string
    {
        return 'youtube_description';
    }

    public function requiredBrainSections(): array
    {
        return [
            'business_description', 'target_audience', 'preferred_keywords',
            'ctas', 'website', 'primary_language',
        ];
    }

    public function buildRequest(array $input, string $brandContext): AiRequest
    {
        $topic = trim((string) ($input['topic'] ?? ''));
        $limit = $this->descriptionLimit();

        return new AiRequest(
            system: $brandContext."\n\n"
                .'Write a YouTube description. Put the most important information in '
                .'the first two lines, because that is all most viewers see before '
                ."they press \"show more\". Stay under {$limit} characters. "
                ."End with the brand's call to action. "
                .'Return only the description text, with no preamble.',
            messages: [[
                'role' => 'user',
                'content' => $topic !== '' ? "Video topic: {$topic}" : 'Write a description for this brand.',
            ]],
            maxTokens: 2048,
            meta: ['feature' => $this->key()],
        );
    }

    public function parseResponse(AiResponse $response): array
    {
        return [
            'description' => mb_substr(trim($response->content), 0, $this->descriptionLimit()),
        ];
    }

    private function descriptionLimit(): int
    {
        return (int) config('social.providers.youtube.channel.limits.description_max', 5000);
    }
}
