<?php

declare(strict_types=1);

namespace App\Domain\AI\Features;

use App\Domain\AI\Contracts\AiFeatureInterface;
use App\Domain\AI\DTO\AiRequest;
use App\Domain\AI\DTO\AiResponse;

/**
 * Candidate YouTube titles.
 *
 * Returns several rather than one: title choice is the highest-leverage
 * decision on YouTube, and the human should pick.
 */
final class YoutubeTitleFeature implements AiFeatureInterface
{
    public function key(): string
    {
        return 'youtube_title';
    }

    public function requiredBrainSections(): array
    {
        return ['business_description', 'target_audience', 'preferred_keywords', 'primary_language'];
    }

    public function buildRequest(array $input, string $brandContext): AiRequest
    {
        $topic = trim((string) ($input['topic'] ?? ''));
        $count = max(1, min(10, (int) ($input['count'] ?? 5)));
        $limit = $this->titleLimit();

        return new AiRequest(
            system: $brandContext."\n\n"
                ."Write {$count} alternative YouTube titles. "
                ."Each must be under {$limit} characters. "
                .'Be specific and accurate -- do not use clickbait that the video '
                .'would not deliver on.',
            messages: [[
                'role' => 'user',
                'content' => $topic !== '' ? "Video topic: {$topic}" : 'Suggest titles for this brand.',
            ]],
            maxTokens: 1024,
            jsonSchema: [
                'type' => 'object',
                'properties' => [
                    'titles' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
                'required' => ['titles'],
            ],
            meta: ['feature' => $this->key()],
        );
    }

    public function parseResponse(AiResponse $response): array
    {
        $decoded = $response->json();
        $limit = $this->titleLimit();
        $titles = [];

        foreach ((array) ($decoded['titles'] ?? []) as $title) {
            $title = trim((string) $title, " \t\n\r\0\x0B\"'");

            // Enforced here as well as instructed: an over-length title would
            // be rejected at publish time, and trimming beats failing the
            // whole generation over one long candidate.
            if ($title !== '') {
                $titles[] = mb_substr($title, 0, $limit);
            }
        }

        return ['titles' => $titles];
    }

    /** From the provider config, so a platform change stays a config edit. */
    private function titleLimit(): int
    {
        return (int) config('social.providers.youtube.channel.limits.title_max', 100);
    }
}
