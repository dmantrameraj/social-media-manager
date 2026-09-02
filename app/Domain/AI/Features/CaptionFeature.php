<?php

declare(strict_types=1);

namespace App\Domain\AI\Features;

use App\Domain\AI\Contracts\AiFeatureInterface;
use App\Domain\AI\DTO\AiRequest;
use App\Domain\AI\DTO\AiResponse;

/**
 * Writes a social caption grounded in the brand profile.
 */
final class CaptionFeature implements AiFeatureInterface
{
    public function key(): string
    {
        return 'caption';
    }

    public function requiredBrainSections(): array
    {
        return [
            'business_description', 'industry', 'target_audience',
            'brand_tone', 'brand_voice_notes', 'usps',
            'preferred_keywords', 'ctas', 'primary_language',
        ];
    }

    public function buildRequest(array $input, string $brandContext): AiRequest
    {
        $topic = trim((string) ($input['topic'] ?? ''));
        $platform = (string) ($input['platform'] ?? 'social media');
        $limit = (int) ($input['character_limit'] ?? 0);

        $instructions = [
            "Write one caption for {$platform}.",
            'Match the brand tone in the profile above.',
            'Do not invent facts, offers, prices or claims that are not in the profile.',
        ];

        if ($limit > 0) {
            // Passed from the provider's own capability limits, so the caption
            // is valid for its destination by construction rather than being
            // rejected at publish time.
            $instructions[] = "Stay under {$limit} characters.";
        }

        $instructions[] = 'Return only the caption text, with no preamble, '
            .'no surrounding quotation marks and no explanation.';

        return new AiRequest(
            system: $brandContext."\n\n".implode(' ', $instructions),
            messages: [[
                'role' => 'user',
                'content' => $topic !== ''
                    ? "Write the caption about: {$topic}"
                    : 'Write a caption suitable for this brand.',
            ]],
            maxTokens: 1024,
            meta: ['feature' => $this->key(), 'platform' => $platform],
        );
    }

    public function parseResponse(AiResponse $response): array
    {
        // Models occasionally wrap a single-line answer in quotes despite
        // being told not to.
        $caption = trim($response->content, " \t\n\r\0\x0B\"'");

        return ['caption' => $caption];
    }
}
