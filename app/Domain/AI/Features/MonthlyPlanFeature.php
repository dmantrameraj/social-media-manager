<?php

declare(strict_types=1);

namespace App\Domain\AI\Features;

use App\Domain\AI\Contracts\AiFeatureInterface;
use App\Domain\AI\DTO\AiRequest;
use App\Domain\AI\DTO\AiResponse;
use Illuminate\Support\Carbon;

/**
 * A month of planned content.
 *
 * The most expensive feature at 25 credits, and the one that most needs to
 * return structure: the output is meant to become real scheduled posts, so it
 * carries dates and platforms rather than prose an editor must retype.
 */
final class MonthlyPlanFeature implements AiFeatureInterface
{
    public function key(): string
    {
        return 'monthly_plan';
    }

    public function requiredBrainSections(): array
    {
        return [
            'business_description', 'industry', 'target_audience', 'locations',
            'products', 'services', 'usps', 'brand_tone', 'goals',
            'content_themes', 'ctas', 'preferred_keywords', 'primary_language',
        ];
    }

    public function buildRequest(array $input, string $brandContext): AiRequest
    {
        $days = max(7, min(60, (int) ($input['days'] ?? 30)));
        $perWeek = max(1, min(14, (int) ($input['posts_per_week'] ?? 4)));
        $start = $this->startDate($input);

        $platforms = $this->platforms($input);

        return new AiRequest(
            system: $brandContext."\n\n"
                ."Plan {$perWeek} posts per week for the next {$days} days, "
                ."starting {$start->toDateString()}. "
                .'Spread the themes so the feed does not repeat itself. '
                .'Vary the formats and the intent -- mix education, proof, '
                .'personality and promotion rather than selling every time. '
                ."Use only these platforms: {$platforms}. "
                .'Give each entry a date in YYYY-MM-DD format inside the window. '
                .'Do not invent offers, prices, events or claims that are not in '
                .'the brand profile.',
            messages: [[
                'role' => 'user',
                'content' => trim((string) ($input['notes'] ?? '')) !== ''
                    ? 'Additional direction: '.trim((string) $input['notes'])
                    : 'Plan the content calendar.',
            ]],
            // The largest output of any feature, hence the largest budget and
            // the highest credit cost.
            maxTokens: 8192,
            jsonSchema: [
                'type' => 'object',
                'properties' => [
                    'entries' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'date' => ['type' => 'string'],
                                'platform' => ['type' => 'string'],
                                'theme' => ['type' => 'string'],
                                'hook' => ['type' => 'string'],
                                'body' => ['type' => 'string'],
                                'format' => ['type' => 'string'],
                            ],
                            'required' => ['date', 'hook'],
                        ],
                    ],
                ],
                'required' => ['entries'],
            ],
            meta: ['feature' => $this->key(), 'days' => $days],
        );
    }

    public function parseResponse(AiResponse $response): array
    {
        $decoded = $response->json();
        $entries = [];

        foreach ((array) ($decoded['entries'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $hook = trim((string) ($entry['hook'] ?? ''));

            if ($hook === '') {
                continue;
            }

            $entries[] = [
                // Normalised here rather than trusted: these become scheduled
                // posts, and an unparseable date would fail much later, in the
                // scheduler, where the cause is far less obvious.
                'date' => $this->normaliseDate($entry['date'] ?? null),
                'platform' => trim((string) ($entry['platform'] ?? '')),
                'theme' => trim((string) ($entry['theme'] ?? '')),
                'hook' => $hook,
                'body' => trim((string) ($entry['body'] ?? '')),
                'format' => trim((string) ($entry['format'] ?? '')),
            ];
        }

        // Chronological, so the plan can be read straight into a calendar.
        usort($entries, static fn (array $a, array $b): int => ($a['date'] ?? '') <=> ($b['date'] ?? ''));

        return ['entries' => $entries];
    }

    /** @param  array<string, mixed>  $input */
    private function startDate(array $input): Carbon
    {
        $raw = (string) ($input['start_date'] ?? '');

        try {
            return $raw !== '' ? Carbon::parse($raw) : Carbon::today();
        } catch (\Throwable) {
            return Carbon::today();
        }
    }

    /**
     * Restricted to the platforms actually enabled, so the plan cannot suggest
     * posting somewhere the product does not support.
     *
     * @param  array<string, mixed>  $input
     */
    private function platforms(array $input): string
    {
        $requested = array_filter(array_map('strval', (array) ($input['platforms'] ?? [])));

        $enabled = array_keys(array_filter(
            (array) config('social.providers', []),
            static fn (array $p): bool => (bool) ($p['enabled'] ?? false),
        ));

        $usable = $requested !== []
            ? array_values(array_intersect($requested, $enabled))
            : $enabled;

        return $usable === [] ? 'any major social platform' : implode(', ', $usable);
    }

    private function normaliseDate(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
