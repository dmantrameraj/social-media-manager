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

    public function permits(int $currentUsage, int $requested = 1): bool
    {
        if ($this->isBoolean()) {
            return $this->isEnabled();
        }

        return $this->isUnlimited()
            || ($currentUsage + $requested) <= $this->limit();
    }
}
