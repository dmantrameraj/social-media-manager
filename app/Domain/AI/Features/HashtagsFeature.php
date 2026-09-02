<?php

declare(strict_types=1);

namespace App\Domain\AI\Features;

use App\Domain\AI\Contracts\AiFeatureInterface;
use App\Domain\AI\DTO\AiRequest;
use App\Domain\AI\DTO\AiResponse;
use Illuminate\Support\Str;

/**
 * Suggests hashtags for a post.
 *
 * Structured: the composer needs a list it can render as chips, not a blob of
 * text to parse by eye.
 */
final class HashtagsFeature implements AiFeatureInterface
{
    public function key(): string
    {
        return 'hashtags';
    }

    public function requiredBrainSections(): array
    {
        // Deliberately narrow -- competitor analysis and CTAs would cost
        // credits and add nothing to a hashtag list.
        return ['industry', 'target_audience', 'preferred_keywords', 'locations'];
    }

    public function buildRequest(array $input, string $brandContext): AiRequest
    {
        $topic = trim((string) ($input['topic'] ?? ''));
        $count = max(1, min(30, (int) ($input['count'] ?? 12)));

        return new AiRequest(
            system: $brandContext."\n\n"
                ."Suggest exactly {$count} hashtags relevant to this brand and the "
                .'given topic. Mix broad and niche tags. Do not repeat a tag. '
                .'Do not include banned, spammy or engagement-bait tags.',
            messages: [[
                'role' => 'user',
                'content' => $topic !== ''
                    ? "Topic: {$topic}"
                    : 'Suggest hashtags suitable for this brand.',
            ]],
            maxTokens: 1024,
            // Structured output: a list to render, not prose to parse.
            jsonSchema: [
                'type' => 'object',
                'properties' => [
                    'hashtags' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                ],
                'required' => ['hashtags'],
            ],
            meta: ['feature' => $this->key()],
        );
    }

    public function parseResponse(AiResponse $response): array
    {
        $decoded = $response->json();

        // Falling back to line parsing rather than failing: a usable list that
        // arrived in the wrong wrapper is still a usable list.
        $raw = is_array($decoded['hashtags'] ?? null)
            ? $decoded['hashtags']
            : (preg_split('/[\s,]+/', $response->content) ?: []);

        $hashtags = [];

        foreach ($raw as $tag) {
            $tag = trim((string) $tag);
            $tag = ltrim($tag, '#');
            $tag = preg_replace('/[^\p{L}\p{N}_]/u', '', $tag) ?? '';

            if ($tag === '') {
                continue;
            }

            $normalised = '#'.$tag;

            // Case-insensitive dedupe: #Coffee and #coffee are one tag.
            if (! in_array(Str::lower($normalised), array_map(Str::lower(...), $hashtags), true)) {
                $hashtags[] = $normalised;
            }
        }

        return ['hashtags' => $hashtags];
    }
}
