<?php

declare(strict_types=1);

namespace App\Domain\AI\Features;

use App\Domain\AI\Contracts\AiFeatureInterface;
use App\Domain\AI\DTO\AiRequest;
use App\Domain\AI\DTO\AiResponse;

/**
 * Rewrites master content to fit one platform.
 *
 * The feature that ties AI to publishing: the limits come from
 * config/social.php, the same source the provider validators use, so an
 * adapted variant is valid for its destination BY CONSTRUCTION rather than
 * being generated and then rejected at publish time.
 *
 * See docs/08-AI-ARCHITECTURE.md §4.
 */
final class PlatformAdaptationFeature implements AiFeatureInterface
{
    public function key(): string
    {
        return 'platform_adaptation';
    }

    public function requiredBrainSections(): array
    {
        return ['brand_tone', 'brand_voice_notes', 'preferred_keywords', 'ctas', 'primary_language'];
    }

    public function buildRequest(array $input, string $brandContext): AiRequest
    {
        $text = trim((string) ($input['text'] ?? ''));
        $provider = (string) ($input['provider_key'] ?? '');
        $accountType = (string) ($input['account_type'] ?? '');

        $rules = $this->platformRules($provider, $accountType);

        return new AiRequest(
            system: $brandContext."\n\n"
                ."Adapt the text below for {$this->platformName($provider)}.\n"
                .implode("\n", $rules)."\n"
                .'Preserve the original meaning and any factual claims exactly. '
                .'Return only the adapted text, with no preamble and no explanation.',
            messages: [[
                'role' => 'user',
                'content' => $text !== '' ? $text : 'No text was supplied.',
            ]],
            maxTokens: 2048,
            meta: ['feature' => $this->key(), 'provider_key' => $provider],
        );
    }

    public function parseResponse(AiResponse $response): array
    {
        return ['text' => trim($response->content, " \t\n\r\0\x0B\"'")];
    }

    /**
     * Rules derived from the provider's own configured capabilities.
     *
     * Reading them here rather than restating them means a platform limit
     * change is still a single config edit.
     *
     * @return list<string>
     */
    private function platformRules(string $provider, string $accountType): array
    {
        $config = (array) config("social.providers.{$provider}", []);

        $accountType = $accountType !== ''
            ? $accountType
            : (string) (($config['account_types'][0]) ?? '');

        $limits = (array) ($config[$accountType]['limits'] ?? []);
        $capabilities = (array) ($config[$accountType]['capabilities'] ?? []);

        $rules = [];

        if (($limits['text_max'] ?? null) !== null) {
            $rules[] = "Stay under {$limits['text_max']} characters.";
        }

        if (($limits['hashtags_max'] ?? null) !== null) {
            $rules[] = "Use at most {$limits['hashtags_max']} hashtags.";
        }

        // Instagram captions cannot carry a clickable link, so telling the
        // reader to "click the link below" produces a dead end.
        if (($capabilities['link'] ?? true) === false) {
            $rules[] = 'Links are not clickable on this platform. '
                .'Do not tell the reader to click a link; point them to the profile instead.';
        }

        if ($rules === []) {
            $rules[] = 'Keep the post concise and native to the platform.';
        }

        return $rules;
    }

    private function platformName(string $provider): string
    {
        return (string) config("social.providers.{$provider}.name", 'this platform');
    }
}
