<?php

declare(strict_types=1);

namespace App\Domain\AI\Features;

use App\Domain\AI\Contracts\AiFeatureInterface;
use App\Domain\AI\DTO\AiRequest;
use App\Domain\AI\DTO\AiResponse;

/**
 * Turns one long-form article into several social posts.
 *
 * The repurposing workflow agencies actually run: one client blog post becomes
 * a fortnight of content.
 */
final class BlogToSocialFeature implements AiFeatureInterface
{
    /**
     * Long articles are the normal input, so the cap is generous. Truncation
     * is reported back rather than happening silently -- a caller who pasted
     * 60,000 words should know only part of it was used.
     */
    private const MAX_ARTICLE_CHARS = 24000;

    public function key(): string
    {
        return 'blog_to_social';
    }

    public function label(): string
    {
        return 'Blog to social';
    }

    public function description(): string
    {
        return 'Turns an article into several posts.';
    }

    public function inputFields(): array
    {
        return [
            ['name' => 'article', 'label' => 'Article', 'type' => 'textarea', 'required' => true],
            ['name' => 'count', 'label' => 'How many posts', 'type' => 'number', 'min' => 1, 'max' => 20],
        ];
    }

    public function requiredBrainSections(): array
    {
        return ['brand_tone', 'brand_voice_notes', 'target_audience', 'ctas', 'primary_language'];
    }

    public function buildRequest(array $input, string $brandContext): AiRequest
    {
        $article = trim((string) ($input['article'] ?? ''));
        $count = max(1, min(15, (int) ($input['count'] ?? 5)));

        $truncated = mb_strlen($article) > self::MAX_ARTICLE_CHARS;
        $article = mb_substr($article, 0, self::MAX_ARTICLE_CHARS);

        return new AiRequest(
            system: $brandContext."\n\n"
                ."Turn the article below into {$count} distinct social posts. "
                .'Each must stand alone and make a different point. '
                .'Use only facts stated in the article -- do not add claims, '
                .'statistics or offers of your own.'
                .($truncated ? ' The article was truncated; work with what is present.' : ''),
            messages: [[
                'role' => 'user',
                'content' => $article !== '' ? $article : 'No article was supplied.',
            ]],
            maxTokens: 4096,
            jsonSchema: [
                'type' => 'object',
                'properties' => [
                    'posts' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'body' => ['type' => 'string'],
                                'angle' => ['type' => 'string'],
                            ],
                            'required' => ['body'],
                        ],
                    ],
                ],
                'required' => ['posts'],
            ],
            meta: ['feature' => $this->key(), 'truncated' => $truncated],
        );
    }

    public function parseResponse(AiResponse $response): array
    {
        $decoded = $response->json();
        $posts = [];

        foreach ((array) ($decoded['posts'] ?? []) as $post) {
            if (! is_array($post)) {
                continue;
            }

            $body = trim((string) ($post['body'] ?? ''));

            if ($body !== '') {
                $posts[] = [
                    'body' => $body,
                    'angle' => trim((string) ($post['angle'] ?? '')),
                ];
            }
        }

        return ['posts' => $posts];
    }
}
