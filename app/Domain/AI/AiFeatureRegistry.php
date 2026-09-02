<?php

declare(strict_types=1);

namespace App\Domain\AI;

use App\Domain\AI\Contracts\AiFeatureInterface;
use App\Domain\AI\Exceptions\UnknownAiFeature;
use App\Domain\AI\Features\BlogToSocialFeature;
use App\Domain\AI\Features\CaptionFeature;
use App\Domain\AI\Features\HashtagsFeature;
use App\Domain\AI\Features\IdeasFeature;
use App\Domain\AI\Features\MonthlyPlanFeature;
use App\Domain\AI\Features\PlatformAdaptationFeature;
use App\Domain\AI\Features\ReelScriptFeature;
use App\Domain\AI\Features\RewriteFeature;
use App\Domain\AI\Features\ToneFeature;
use App\Domain\AI\Features\TranslateFeature;
use App\Domain\AI\Features\YoutubeDescriptionFeature;
use App\Domain\AI\Features\YoutubeTitleFeature;

/**
 * Resolves AI features by key.
 *
 * Controllers and jobs pass a key from a request; this is the only place that
 * turns one into a class. Keeping it explicit rather than deriving a class
 * name from user input means a crafted key cannot reach an arbitrary class.
 */
final class AiFeatureRegistry
{
    /** @var array<string, class-string<AiFeatureInterface>> */
    private const FEATURES = [
        'caption' => CaptionFeature::class,
        'hashtags' => HashtagsFeature::class,
        'ideas' => IdeasFeature::class,
        'rewrite' => RewriteFeature::class,
        'tone' => ToneFeature::class,
        'translate' => TranslateFeature::class,
        'platform_adaptation' => PlatformAdaptationFeature::class,
        'reel_script' => ReelScriptFeature::class,
        'youtube_title' => YoutubeTitleFeature::class,
        'youtube_description' => YoutubeDescriptionFeature::class,
        'blog_to_social' => BlogToSocialFeature::class,
        'monthly_plan' => MonthlyPlanFeature::class,
    ];

    public function get(string $key): AiFeatureInterface
    {
        $map = self::FEATURES;

        if (! array_key_exists($key, $map)) {
            throw new UnknownAiFeature($key);
        }

        return app($map[$key]);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, self::FEATURES);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys(self::FEATURES);
    }

    /**
     * Features with a credit cost configured, in cost order.
     *
     * Drives the UI so a feature is never offered without a price.
     *
     * @return array<string, int>
     */
    public function withCosts(): array
    {
        $costs = (array) config('ai.costs', []);
        $result = [];

        foreach ($this->keys() as $key) {
            $result[$key] = max(1, (int) ($costs[$key] ?? 1));
        }

        asort($result);

        return $result;
    }
}
