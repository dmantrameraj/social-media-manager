<?php

declare(strict_types=1);

use App\Domain\Billing\Contracts\PaymentGatewayInterface;
use App\Domain\Billing\Gateways\ManualGateway;
use App\Domain\Billing\Gateways\RazorpayGateway;
use App\Domain\Billing\Models\WebhookEvent;

beforeEach(function (): void {
    config()->set('services.razorpay.secret', 'test-api-secret');
    config()->set('services.razorpay.webhook_secret', 'test-webhook-secret');

    $this->gateway = app(RazorpayGateway::class);
});

function signBody(string $body, string $secret = 'test-webhook-secret'): string
{
    return hash_hmac('sha256', $body, $secret);
}

function webhookBody(string $eventId = 'evt_001', string $event = 'subscription.charged'): string
{
    return json_encode([
        'event' => $event,
        '__event_id' => $eventId,
        'payload' => [
            'subscription' => ['entity' => ['id' => 'sub_123']],
            'payment' => ['entity' => ['id' => 'pay_456']],
        ],
    ], JSON_THROW_ON_ERROR);
}

// ------------------------------------------------------------ signature checks

it('accepts a correctly signed webhook', function (): void {
    $body = webhookBody();

    $this->call('POST', route('webhooks.razorpay'), [], [], [], [
        'HTTP_X-Razorpay-Signature' => signBody($body),
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertOk();

    expect(WebhookEvent::query()->count())->toBe(1);
});

it('rejects a tampered body', function (): void {
    $body = webhookBody();
    $signature = signBody($body);

    // Same signature, altered payload -- the classic replay-with-edits attack.
    $tampered = str_replace('sub_123', 'sub_ATTACKER', $body);

    $this->call('POST', route('webhooks.razorpay'), [], [], [], [
        'HTTP_X-Razorpay-Signature' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ], $tampered)->assertStatus(400);

    expect(WebhookEvent::query()->count())->toBe(0);
});

it('rejects a webhook with no signature', function (): void {
    $body = webhookBody();

    $this->call('POST', route('webhooks.razorpay'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertStatus(400);
});

it('rejects a signature made with the wrong secret', function (): void {
    $body = webhookBody();

    $this->call('POST', route('webhooks.razorpay'), [], [], [], [
        'HTTP_X-Razorpay-Signature' => signBody($body, 'not-the-real-secret'),
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertStatus(400);
});

it('fails closed when no webhook secret is configured', function (): void {
    config()->set('services.razorpay.webhook_secret', '');

    // A missing secret must never read as "everything is valid".
    expect($this->gateway->verifyWebhookSignature('body', 'anything'))->toBeFalse();
});

// -------------------------------------------------------------- deduplication

it('processes a repeated delivery only once', function (): void {
    $body = webhookBody('evt_dup');
    $headers = [
        'HTTP_X-Razorpay-Signature' => signBody($body),
        'CONTENT_TYPE' => 'application/json',
    ];

    $this->call('POST', route('webhooks.razorpay'), [], [], [], $headers, $body)->assertOk();
    $this->call('POST', route('webhooks.razorpay'), [], [], [], $headers, $body)->assertOk();

    // Second delivery still returns 200 so the gateway stops retrying, but no
    // duplicate row is created.
    expect(WebhookEvent::query()->count())->toBe(1);
});

it('records distinct events separately', function (): void {
    foreach (['evt_a', 'evt_b'] as $id) {
        $body = webhookBody($id);

        $this->call('POST', route('webhooks.razorpay'), [], [], [], [
            'HTTP_X-Razorpay-Signature' => signBody($body),
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertOk();
    }

    expect(WebhookEvent::query()->count())->toBe(2);
});

it('stores the parsed event type and marks the signature verified', function (): void {
    $body = webhookBody('evt_typed', 'subscription.halted');

    $this->call('POST', route('webhooks.razorpay'), [], [], [], [
        'HTTP_X-Razorpay-Signature' => signBody($body),
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertOk();

    $record = WebhookEvent::query()->firstOrFail();

    expect($record->event_type)->toBe('subscription.halted')
        ->and($record->signature_verified)->toBeTrue()
        ->and($record->status)->toBe('pending')
        ->and($record->processed_at)->toBeNull();
});

it('rejects a malformed body even when correctly signed', function (): void {
    $body = 'this is not json';

    $this->call('POST', route('webhooks.razorpay'), [], [], [], [
        'HTTP_X-Razorpay-Signature' => signBody($body),
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertStatus(400);
});

// -------------------------------------------------------- checkout signatures

it('verifies a valid checkout signature', function (): void {
    $orderId = 'order_abc';
    $paymentId = 'pay_xyz';

    expect($this->gateway->verifyPaymentSignature([
        'razorpay_order_id' => $orderId,
        'razorpay_payment_id' => $paymentId,
        'razorpay_signature' => hash_hmac('sha256', $orderId.'|'.$paymentId, 'test-api-secret'),
    ]))->toBeTrue();
});

it('rejects a forged checkout signature', function (): void {
    // What a client would send if it simply claimed success.
    expect($this->gateway->verifyPaymentSignature([
        'razorpay_order_id' => 'order_abc',
        'razorpay_payment_id' => 'pay_xyz',
        'razorpay_signature' => 'i-made-this-up',
    ]))->toBeFalse();
});

it('rejects a checkout payload with missing fields', function (): void {
    expect($this->gateway->verifyPaymentSignature(['razorpay_order_id' => 'order_abc']))
        ->toBeFalse();
});

// ---------------------------------------------------------------- manual gateway

it('makes the manual gateway share the lifecycle interface', function (): void {
    $manual = app(ManualGateway::class);

    // Same contract as Razorpay, so subscription code needs no branch.
    expect($manual)->toBeInstanceOf(PaymentGatewayInterface::class)
        // No checkout exists, so verification fails closed rather than open.
        ->and($manual->verifyPaymentSignature([]))->toBeFalse()
        ->and($manual->verifyWebhookSignature('x', 'y'))->toBeFalse();
});
