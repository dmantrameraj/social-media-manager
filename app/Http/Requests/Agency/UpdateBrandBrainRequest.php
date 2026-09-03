<?php

declare(strict_types=1);

namespace App\Http\Requests\Agency;

use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Validates and normalises a brand profile.
 *
 * The list fields are the awkward part: they are JSON arrays in the database
 * but a person types them as lines in a textarea. Doing that conversion here,
 * once, keeps the controller free of parsing and means every list is cleaned
 * the same way.
 */
final class UpdateBrandBrainRequest extends FormRequest
{
    /**
     * Fields stored as JSON arrays and entered as one item per line.
     *
     * @var list<string>
     */
    public const LIST_FIELDS = [
        'target_audience', 'locations', 'products', 'services', 'usps',
        'competitors', 'ctas', 'forbidden_words', 'preferred_keywords',
        'content_themes', 'goals',
    ];

    /** Free-text and scalar fields. */
    private const TEXT_FIELDS = [
        'business_description', 'industry', 'website',
        'brand_tone', 'brand_voice_notes', 'primary_language',
    ];

    public function authorize(): bool
    {
        return $this->user() instanceof User
            && $this->user()->can('ai.manage_brand_brain');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'business_description' => ['nullable', 'string', 'max:5000'],
            'industry' => ['nullable', 'string', 'max:120'],
            'website' => ['nullable', 'url', 'max:255'],
            'brand_tone' => ['nullable', 'string', 'max:190'],
            'brand_voice_notes' => ['nullable', 'string', 'max:5000'],
            'primary_language' => ['nullable', 'string', 'max:10'],

            /*
             | Each list arrives as a textarea. Capped at 4000 characters per
             | field: this text is injected into every prompt for this brand, so
             | an unbounded paste is a bill as well as a context-window problem.
             */
            ...array_fill_keys(
                self::LIST_FIELDS,
                ['nullable', 'string', 'max:4000'],
            ),
        ];
    }

    /**
     * The validated data, with list fields split into arrays.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $validated = $this->validated();

        $payload = [];

        foreach (self::TEXT_FIELDS as $field) {
            $value = trim((string) ($validated[$field] ?? ''));

            // Null rather than '' so completeness() counts it as absent. An
            // empty string would read as "filled in" and inflate the figure the
            // user relies on to judge output quality.
            $payload[$field] = $value === '' ? null : $value;
        }

        foreach (self::LIST_FIELDS as $field) {
            $payload[$field] = $this->toList($validated[$field] ?? null);
        }

        // primary_language has a database default; blank must not overwrite it.
        $payload['primary_language'] ??= 'en';

        return $payload;
    }

    /**
     * One item per line, trimmed, blanks dropped, duplicates removed.
     *
     * @return list<string>
     */
    private function toList(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        return collect(preg_split('/\r\n|\r|\n/', $raw) ?: [])
            ->map(static fn (string $line): string => trim($line))
            ->filter(static fn (string $line): bool => $line !== '')
            // Deduplicated case-insensitively: "Vegan" and "vegan" in a
            // forbidden-words list are the same instruction, and repeating an
            // item in a prompt does not make the model obey it harder.
            ->unique(static fn (string $line): string => Str::lower($line))
            ->take(100)
            ->values()
            ->all();
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'website.url' => 'Include the full address, starting with https://',
        ];
    }
}
