<?php

declare(strict_types=1);

namespace App\Domain\Billing\Gateways;

use App\Domain\Billing\Contracts\PaymentGatewayInterface;
use App\Domain\Billing\Contracts\WebhookEventData;
use App\Domain\Billing\Enums\PaymentGateway;

/**
 * Razorpay.
 *
 * [VERIFY] Endpoint paths, request/response field names, subscription state
 * names and webhook event names must be confirmed against current official
 * Razorpay documentation before the API-calling methods are implemented.
 * Only the cryptographic verification below is implemented here, because it is
 * the part that must be right before any money moves and it depends on nothing
 * we would have to guess.
 *
 * See docs/09-BILLING.md §6.
 */
final class RazorpayGateway implements PaymentGatewayInterface
{
    public function key(): PaymentGateway
    {
        return PaymentGateway::Razorpay;
    }

    /**
     * Checkout returns identifiers plus a signature. The signature is an
     * HMAC-SHA256 over "{order_id}|{payment_id}" keyed with the API secret.
     *
     * A browser saying "payment succeeded" proves nothing; this is what makes
     * it trustworthy.
     *
     * @param  array<string, string>  $payload
     */
    public function verifyPaymentSignature(array $payload): bool
    {
        $orderId = $payload['razorpay_order_id'] ?? null;
        $paymentId = $payload['razorpay_payment_id'] ?? null;
        $signature = $payload['razorpay_signature'] ?? null;

        if (! is_string($orderId) || ! is_string($paymentId) || ! is_string($signature)) {
            return false;
        }

        $secret = (string) config('services.razorpay.secret');

        if ($secret === '') {
            // Fail closed. A missing secret must never read as "valid".
            return false;
        }

        $expected = hash_hmac('sha256', $orderId.'|'.$paymentId, $secret);

        // hash_equals, not ===, so comparison time does not leak the signature.
        return hash_equals($expected, $signature);
    }

    /**
     * Webhook signature: HMAC-SHA256 of the RAW request body, keyed with the
     * webhook secret (which is distinct from the API secret).
     */
    public function verifyWebhookSignature(string $rawBody, string $signature): bool
    {
        $secret = (string) config('services.razorpay.webhook_secret');

        if ($secret === '' || $signature === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function parseWebhook(array $payload): WebhookEventData
    {
        $entities = $payload['payload'] ?? [];

        return new WebhookEventData(
            // Razorpay sends x-razorpay-event-id as a header; the controller
            // passes it through in the payload under this key so dedupe has a
            // stable identifier even when the body repeats.
            eventId: (string) ($payload['__event_id']
                ?? data_get($payload, 'payload.payment.entity.id')
                ?? md5(json_encode($payload) ?: '')),
            eventType: (string) ($payload['event'] ?? 'unknown'),
            payload: $payload,
            gatewaySubscriptionId: data_get($entities, 'subscription.entity.id'),
            gatewayPaymentId: data_get($entities, 'payment.entity.id'),
        );
    }
}
