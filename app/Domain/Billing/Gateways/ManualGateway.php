<?php

declare(strict_types=1);

namespace App\Domain\Billing\Gateways;

use App\Domain\Billing\Contracts\PaymentGatewayInterface;
use App\Domain\Billing\Contracts\WebhookEventData;
use App\Domain\Billing\Enums\PaymentGateway;
use RuntimeException;

/**
 * Admin-activated subscriptions.
 *
 * Implementing the same interface is the point: expiry, grace and suspension
 * behave identically for manual and paid tenants, and the lifecycle code has no
 * idea which is which. Without this, manual activation becomes a second,
 * half-maintained code path -- which is how sales-created accounts end up
 * failing to expire.
 *
 * See docs/09-BILLING.md §8.
 */
final class ManualGateway implements PaymentGatewayInterface
{
    public function key(): PaymentGateway
    {
        return PaymentGateway::Manual;
    }

    /**
     * There is no checkout, so there is no signature to verify. Returning
     * false rather than true is deliberate: if this is ever reached, something
     * has routed a real payment down the manual path, and failing closed is
     * the safe outcome.
     *
     * @param  array<string, string>  $payload
     */
    public function verifyPaymentSignature(array $payload): bool
    {
        return false;
    }

    public function verifyWebhookSignature(string $rawBody, string $signature): bool
    {
        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function parseWebhook(array $payload): WebhookEventData
    {
        throw new RuntimeException('The manual gateway does not receive webhooks.');
    }
}
