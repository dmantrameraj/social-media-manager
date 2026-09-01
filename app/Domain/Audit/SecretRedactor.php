<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use Illuminate\Support\Str;

/**
 * Strips secrets out of arbitrary attribute arrays before they are persisted
 * or logged.
 *
 * Matching is on the KEY, case-insensitively, as a substring -- so
 * `client_secret`, `META_CLIENT_SECRET` and `secretKey` are all caught without
 * needing an exhaustive list. Over-redacting costs a little forensic detail;
 * under-redacting costs a credential.
 */
final class SecretRedactor
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function redact(array $attributes): array
    {
        $patterns = array_map(
            static fn (string $p): string => Str::lower($p),
            (array) config('audit.redacted_attributes', []),
        );

        $placeholder = (string) config('audit.placeholder', '[redacted]');
        $maxLength = (int) config('audit.max_value_length', 2000);

        $result = [];

        foreach ($attributes as $key => $value) {
            $lowerKey = Str::lower((string) $key);

            foreach ($patterns as $pattern) {
                if (str_contains($lowerKey, $pattern)) {
                    $result[$key] = $placeholder;

                    continue 2;
                }
            }

            $result[$key] = $this->normalise($value, $maxLength, $patterns, $placeholder);
        }

        return $result;
    }

    /**
     * Nested arrays are walked so a secret one level down -- inside a `meta`
     * or `settings` blob -- is caught too.
     */
    private function normalise(
        mixed $value,
        int $maxLength,
        array $patterns,
        string $placeholder,
    ): mixed {
        if (is_array($value)) {
            $nested = [];

            foreach ($value as $k => $v) {
                $lowerKey = Str::lower((string) $k);

                foreach ($patterns as $pattern) {
                    if (str_contains($lowerKey, $pattern)) {
                        $nested[$k] = $placeholder;

                        continue 2;
                    }
                }

                $nested[$k] = $this->normalise($v, $maxLength, $patterns, $placeholder);
            }

            return $nested;
        }

        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (is_string($value) && mb_strlen($value) > $maxLength) {
            // An audit log records WHAT changed, not a second copy of content.
            return mb_substr($value, 0, $maxLength).'…[truncated]';
        }

        return $value;
    }

    /**
     * Drop attributes that change on nearly every write and would bury the
     * changes that matter.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function withoutNoise(array $attributes): array
    {
        return array_diff_key(
            $attributes,
            array_flip((array) config('audit.ignored_attributes', [])),
        );
    }
}
