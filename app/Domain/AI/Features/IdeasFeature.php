<?php

declare(strict_types=1);

namespace App\Domain\AI\Features;

use App\Domain\AI\Contracts\AiFeatureInterface;
use App\Domain\AI\DTO\AiRequest;
use App\Domain\AI\DTO\AiResponse;

/**
 * Content ideas for a brand, as a structured list the UI can render as cards.
 */
final class IdeasFeature implements AiFeatureInterface
{
    public function key(): string
    {
        return 'ideas';
    }

    public function label(): string
    {
        return 'Content ideas';
    }

    public function description(): string
    {
        return 'Post ideas for a theme, grounded in the brand profile.';
    }

    public function inputFields(): array
    {
        return [
            ['name' => 'theme', 'label' => 'Theme', 'type' => 'text', 'help' => 'A season, campaign or subject to generate around.'],
            ['name' => 'count', 'label' => 'How many', 'type' => 'number', 'min' => 1, 'max' => 50],
        ];
    }

    public function requiredBrainSections(): array
    {
        return [
            'business_description', 'industry', 'target_audience',
            'products', 'services', 'usps', 'content_themes', 'goals',
            'primary_language',
        ];
    }

    public function buildRequest(array $input, string $brandContext): AiRequest
    {
        $count = max(1, min(20, (int) ($input['count'] ?? 8)));
        $theme = trim((string) ($input['theme'] ?? ''));

        return new AiRequest(
            system: $brandContext."\n\n"
                ."Suggest {$count} distinct social content ideas for this brand. "
                .'Each needs a short hook and one line explaining the angle. '
                .'Vary the formats. Do not invent offers, prices or claims that are '
                .'not in the profile.',
            messages: [[
                'role' => 'user',
                'content' => $theme !== ''
                    ? "Focus on this theme: {$theme}"
                    : 'Suggest ideas across the brand\'s usual themes.',
            ]],
            maxTokens: 3000,
            jsonSchema: [
                'type' => 'object',
                'properties' => [
                    'ideas' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'hook' => ['type' => 'string'],
                                'angle' => ['type' => 'string'],
                                'format' => ['type' => 'string'],
                            ],
                            'required' => ['hook', 'angle'],
                        ],
                    ],
                ],
                'required' => ['ideas'],
            ],
            meta: ['feature' => $this->key()],
        );
    }

    public function parseResponse(AiResponse $response): array
    {
        $decoded = $response->json();
        $ideas = [];

        foreach ((array) ($decoded['ideas'] ?? []) as $idea) {
            if (! is_array($idea)) {
                continue;
            }

            $hook = trim((string) ($idea['hook'] ?? ''));

            if ($hook === '') {
                continue;
            }

            $ideas[] = [
                'hook' => $hook,
                'angle' => trim((string) ($idea['angle'] ?? '')),
                'format' => trim((string) ($idea['format'] ?? '')),
            ];
        }

        return ['ideas' => $ideas];
    }
}
