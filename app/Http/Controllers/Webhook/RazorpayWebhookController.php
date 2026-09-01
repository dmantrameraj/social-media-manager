<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhook;

use App\Domain\Billing\Gateways\RazorpayGateway;
use App\Domain\Billing\Models\WebhookEvent;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Razorpay webhook endpoint.
 *
 * Does four things and nothing else: verify, deduplicate, record, return 200.
 * All actual work is queued, so a slow handler can never make the gateway
 * retry-storm. See docs/09-BILLING.md §6.
 */
final class RazorpayWebhookController
{
    public function __construct(private readonly RazorpayGateway $gateway) {}

    public function __invoke(Request $request): JsonResponse
    {
        // getContent(), not $request->all(): re-encoding a parsed array changes
        // key order and whitespace, which breaks the HMAC.
        $rawBody = $request->getContent();
        $signature = (string) $request->header('X-Razorpay-Signature', '');

        if (! $this->gateway->verifyWebhookSignature($rawBody, $signature)) {
            // Logged WITHOUT the payload: an unverified body is attacker-
            // controlled and may be an attempt to poison the logs.
            Log::warning('Rejected Razorpay webhook with an invalid signature.', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => 'Invalid signature.'], 400);
        }

        $payload = json_decode($rawBody, true);

        if (! is_array($payload)) {
            return response()->json(['message' => 'Malformed payload.'], 400);
        }

        // The gateway's own event id is the dedupe anchor when present.
        $payload['__event_id'] = $request->header('X-Razorpay-Event-Id')
            ?? ($payload['__event_id'] ?? null);

        $event = $this->gateway->parseWebhook($payload);

        $alreadySeen = WebhookEvent::query()
            ->where('provider', 'razorpay')
            ->where('event_id', $event->eventId)
            ->exists();

        if ($alreadySeen) {
            // 200, so the gateway stops retrying a delivery we already hold.
            return response()->json(['message' => 'Already received.']);
        }

        try {
            // forceCreate: the model is fully guarded because nothing
            // user-supplied may be mass assigned onto it. Every value here is
            // assembled locally from an already signature-verified payload.
            WebhookEvent::query()->forceCreate([
                'provider' => 'razorpay',
                'event_id' => $event->eventId,
                'event_type' => $event->eventType,
                'signature_verified' => true,
                'payload' => $event->payload,
                'status' => 'pending',
                'attempts' => 0,
                'received_at' => now(),
            ]);
        } catch (QueryException) {
            // Two deliveries raced past the check above. The unique key on
            // (provider, event_id) is the real guarantee; this is simply the
            // loser of that race, and it must still answer 200.
            return response()->json(['message' => 'Already received.']);
        }

        // ProcessRazorpayWebhook is dispatched here once the subscription
        // handlers land (Step 9 completion) -- the row is durable in the
        // meantime, so nothing is lost by queuing it later.

        return response()->json(['message' => 'Received.']);
    }
}
