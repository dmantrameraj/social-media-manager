<?php

declare(strict_types=1);

namespace App\Domain\Billing\Entitlements;

use App\Domain\Billing\Entitlements\Enums\EntitlementType;

/**
 * A resolved limit for one tenant and one key.
 *
 * Immutable, and carries its own provenance: knowing an override produced a
 * value -- rather than the plan -- is what makes a support conversation about
 * "why can this tenant do that?" answerable.
 */
final readonly class Entitlement
{
    public function __construct(
        public string $key,
        public EntitlementType $type,
        public ?int $value,
        public string $source,
    ) {}

    public static function unlimited(string $key, string $source): self
    {
        return new self($key, EntitlementType::Unlimited, null, $source);
    }

    public function isUnlimited(): bool
    {
        return $this->type === EntitlementType::Unlimited;
    }

    public function isBoolean(): bool
    {
        return $this->type === EntitlementType::Boolean;
    }

    /** Boolean entitlements store 1/0 in the same column as limits. */
    public function isEnabled(): bool
    {
        return $this->isUnlimited() || (bool) $this->value;
    }

    /**
     * The numeric ceiling. PHP_INT_MAX for unlimited, so callers can compare
     * without special-casing -- an unlimited entitlement is just a very large
     * one at the point of comparison.
     */
    public function limit(): int
    {
        return $this->isUnlimited() ? PHP_INT_MAX : (int) $this->value;
    }

    /**
     * A cache-safe representation.
     *
     * Entitlements are cached, and a cache must never hold a serialized PHP
     * object: `cache.serializable_classes` is `false` by default in Laravel 13
     * (a deliberate defence against gadget chains if APP_KEY leaks), so an
     * object written to cache reads back as __PHP_Incomplete_Class and fatals
     * at the return type. Storing scalars also means renaming a property here
     * cannot poison every cache entry across a deploy.
     *
     * @return array{key: string, type: string, value: int|null, source: string}
     */
    public function toCacheArray(): array
    {
        return [
            'key' => $this->key,
            'type' => $this->type->value,
            'value' => $this->value,
            'source' => $this->source,
        ];
    }

    /**
     * Rebuild from {@see toCacheArray()}. Returns null for anything that is not
     * a well-formed payload, so a stale or foreign entry is treated as a cache
     * miss and recomputed rather than crashing the request.
     *
     * @param  mixed  $payload
     */
    public static function fromCacheArray($payload): ?self
    {
        if (! is_array($payload)
            || ! isset($payload['key'], $payload['type'], $payload['source'])
            || ! array_key_exists('value', $payload)) {
            return null;
        }

        $type = EntitlementType::tryFrom((string) $payload['type']);

        if ($type === null) {
            return null;
        }

        return new self(
            (string) $payload['key'],
            $type,
            $payload['value'] === null ? null : (int) $payload['value'],
            (string) $payload['source'],
        );
    }

    public function permits(int $currentUsage, int $requested = 1): bool
    {
        if ($this->isBoolean()) {
            return $this->isEnabled();
        }

        return $this->isUnlimited()
            || ($currentUsage + $requested) <= $this->limit();
    }
}
