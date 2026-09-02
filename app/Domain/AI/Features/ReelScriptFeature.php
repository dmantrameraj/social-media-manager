<?php

declare(strict_types=1);

namespace App\Domain\AI\Features;

use App\Domain\AI\Contracts\AiFeatureInterface;
use App\Domain\AI\DTO\AiRequest;
use App\Domain\AI\DTO\AiResponse;

/**
 * A shot-by-shot script for a Reel or Short.
 *
 * Structured by scene so the designer can work from it directly rather than
 * re-reading a wall of prose.
 */
final class ReelScriptFeature implements AiFeatureInterface
{
    public function key(): string
    {
        return 'reel_script';
    }

    public function requiredBrainSections(): array
    {
        return [
            'business_description', 'target_audience', 'brand_tone',
            'products', 'services', 'usps', 'ctas', 'primary_language',
        ];
    }

    public function buildRequest(array $input, string $brandContext): AiRequest
    {
        $topic = trim((string) ($input['topic'] ?? ''));
        $seconds = max(10, min(90, (int) ($input['duration_seconds'] ?? 30)));

        return new AiRequest(
            system: $brandContext."\n\n"
                ."Write a {$seconds}-second vertical video script. "
                .'Open with a hook in the first three seconds. '
                .'Break it into scenes, each with the on-screen visual, the spoken '
                .'or on-screen text, and its approximate duration. '
                .'End with a single clear call to action.',
            messages: [[
                'role' => 'user',
                'content' => $topic !== '' ? "Topic: {$topic}" : 'Choose a topic that suits this brand.',
            ]],
            maxTokens: 3000,
            jsonSchema: [
                'type' => 'object',
                'properties' => [
                    'hook' => ['type' => 'string'],
                    'scenes' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'visual' => ['type' => 'string'],
                                'text' => ['type' => 'string'],
                                'seconds' => ['type' => 'number'],
                            ],
                            'required' => ['visual', 'text'],
                        ],
                    ],
                    'call_to_action' => ['type' => 'string'],
                ],
                'required' => ['hook', 'scenes'],
            ],
            meta: ['feature' => $this->key()],
        );
    }

    public function parseResponse(AiResponse $response): array
    {
        $decoded = $response->json();
        $scenes = [];

        foreach ((array) ($decoded['scenes'] ?? []) as $scene) {
            if (! is_array($scene)) {
                continue;
            }

            $scenes[] = [
                'visual' => trim((string) ($scene['visual'] ?? '')),
                'text' => trim((string) ($scene['text'] ?? '')),
                'seconds' => (int) round((float) ($scene['seconds'] ?? 0)),
            ];
        }

        return [
            'hook' => trim((string) ($decoded['hook'] ?? '')),
            'scenes' => $scenes,
            'call_to_action' => trim((string) ($decoded['call_to_action'] ?? '')),
        ];
    }
}
