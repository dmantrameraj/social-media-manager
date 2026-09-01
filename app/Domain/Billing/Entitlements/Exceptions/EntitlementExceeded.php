<?php

declare(strict_types=1);

namespace App\Domain\Billing\Entitlements\Exceptions;

use App\Domain\Billing\Entitlements\Entitlement;
use RuntimeException;

/**
 * A tenant tried to exceed a plan limit.
 *
 * Carries the key and the ceiling so the UI can render a specific message and
 * an upgrade CTA naming the limit that was hit. It is rendered as a clear
 * message, never a 500 -- see docs/09-BILLING.md §3.
 */
final class EntitlementExceeded extends RuntimeException
{
    public function __construct(
        public readonly Entitlement $entitlement,
        public readonly int $currentUsage,
    ) {
        // Literal key lookup: entitlement keys contain dots, which the config
        // helper would read as nested traversal.
        $definitions = (array) config('entitlements.keys', []);
        $label = (string) ($definitions[$entitlement->key]['label'] ?? $entitlement->key);

        parent::__construct(
            $entitlement->isBoolean()
                ? "Your plan does not include {$label}."
                : "You have reached your plan limit for {$label} ({$entitlement->limit()})."
        );
    }

    public function key(): string
    {
        return $this->entitlement->key;
    }
}
