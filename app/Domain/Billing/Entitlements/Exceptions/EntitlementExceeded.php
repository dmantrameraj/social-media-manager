<?php

declare(strict_types=1);

namespace App\Domain\Billing\Entitlements\Exceptions;

use App\Domain\Billing\Entitlements\Entitlement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    /**
     * Hitting a plan limit is a normal answer, not a crash.
     *
     * Without this an uncaught one is a 500: the same event reads as "you have
     * reached your limit" on the paths a controller happens to catch, and as
     * the application falling over everywhere else. Laravel calls render() on
     * an exception that defines it, so this covers every path that does not
     * catch it explicitly -- including ones added later by somebody who has
     * never read this class.
     *
     * `upgrade_prompt` is what the flash partial reads to offer a way out.
     */
    public function render(Request $request): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return new JsonResponse([
                'message' => $this->getMessage(),
                'limit' => $this->key(),
            ], 403);
        }

        return back()
            ->withInput()
            ->with('error', $this->getMessage())
            ->with('upgrade_prompt', true);
    }
}
