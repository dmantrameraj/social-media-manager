<?php

declare(strict_types=1);

namespace App\Domain\Billing\Contracts;

use App\Domain\Billing\Enums\PaymentGateway;

/**
 * The seam that keeps subscription lifecycle code free of gateway specifics.
 *
 * ManualGateway implements this too, with no-op payment operations, so an
 * admin-activated tenant traverses exactly the same lifecycle as a paying one
 * and there is no "if manual" branch anywhere in Domain/Billing/Subscriptions.
 *
 * See docs/09-BILLING.md §1.
 */
interface PaymentGatewayInterface
{
    public function key(): PaymentGateway;

    /**
     * Verify a signature returned to the browser by the gateway's checkout.
     *
     * A client-reported success is never trusted: this runs server-side before
     * anything is marked paid.
     *
     * @param  array<string, string>  $payload
     */
    public function verifyPaymentSignature(array $payload): bool;

    /**
     * Verify an inbound webhook against the raw request body.
     *
     * The RAW body matters: re-encoding a parsed array changes key order and
     * whitespace, which breaks the HMAC.
     */
    public function verifyWebhookSignature(string $rawBody, string $signature): bool;

    /**
     * Normalise a provider payload into our own shape, so webhook handling
     * code never reads gateway-specific field names.
     *
     * @param  array<string, mixed>  $payload
     */
    public function parseWebhook(array $payload): WebhookEventData;
}
