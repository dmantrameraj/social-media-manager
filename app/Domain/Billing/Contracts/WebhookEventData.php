<?php

declare(strict_types=1);

namespace App\Domain\Billing\Contracts;

/**
 * A gateway webhook, normalised.
 *
 * Handlers read these fields rather than provider-specific payload keys, so
 * adding Stripe later does not touch the handling code.
 */
final readonly class WebhookEventData
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $eventId,
        public string $eventType,
        public array $payload,
        public ?string $gatewaySubscriptionId = null,
        public ?string $gatewayPaymentId = null,
    ) {}
}
