<?php

declare(strict_types=1);

namespace App\Domain\AI\Services;

use App\Domain\AI\Credits\CreditLedger;
use App\Domain\AI\Credits\Enums\CreditTransactionType;
use App\Domain\AI\Models\AiCreditTransaction;
use App\Domain\AI\Models\AiGeneration;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Releases credits held by generations that never finished.
 *
 * A worker killed mid-generation leaves the reservation in place: the credits
 * are neither spent nor available, so a tenant slowly loses spending power
 * with nothing to show for it. Without this sweep that leak is permanent.
 *
 * A pending generation older than the TTL is the signal, rather than parsing
 * idempotency keys -- it is real state, and it is what the admin UI would show
 * a human anyway.
 *
 * See docs/08-AI-ARCHITECTURE.md §5.
 */
final class SweepStaleReservationsService
{
    public function __construct(private readonly CreditLedger $ledger) {}

    /**
     * @return array{swept: int, credits_released: int}
     */
    public function execute(): array
    {
        $ttl = (int) config('ai.reservation_ttl', 900);
        $cutoff = now()->subSeconds($ttl);

        $swept = 0;
        $released = 0;

        AiGeneration::query()
            ->acrossTenants()
            ->where('status', 'pending')
            ->where('created_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById(100, function ($generations) use (&$swept, &$released): void {
                foreach ($generations as $generation) {
                    $amount = $this->reservedAmount($generation);

                    if ($amount <= 0) {
                        // No reservation to return -- still close the row so
                        // it stops being swept forever.
                        $this->markTimedOut($generation, 0);
                        $swept++;

                        continue;
                    }

                    $tenant = Tenant::query()->find($generation->tenant_id);

                    if ($tenant === null) {
                        continue;
                    }

                    DB::transaction(function () use ($tenant, $generation, $amount): void {
                        $this->ledger->release(
                            $tenant,
                            $amount,
                            'Reservation swept after timeout',
                            $generation,
                            // Idempotent: a second sweep of the same row is
                            // rejected by the unique key rather than
                            // double-releasing.
                            'sweep:'.$generation->getKey(),
                        );

                        $this->markTimedOut($generation, $amount);
                    });

                    $swept++;
                    $released += $amount;
                }
            });

        return ['swept' => $swept, 'credits_released' => $released];
    }

    /**
     * Credits still held for this generation: what was reserved, less anything
     * already consumed or released.
     */
    private function reservedAmount(AiGeneration $generation): int
    {
        $transactions = AiCreditTransaction::query()
            ->acrossTenants()
            ->where('reference_type', AiGeneration::class)
            ->where('reference_id', $generation->getKey())
            ->get();

        $reserved = 0;
        $settled = 0;

        foreach ($transactions as $transaction) {
            match ($transaction->type) {
                CreditTransactionType::Reserve => $reserved += abs($transaction->amount),
                CreditTransactionType::Consume,
                CreditTransactionType::Release => $settled += abs($transaction->amount),
                default => null,
            };
        }

        return max(0, $reserved - $settled);
    }

    private function markTimedOut(AiGeneration $generation, int $released): void
    {
        $generation->forceFill([
            'status' => 'failed',
            'error_code' => 'reservation_timeout',
            'error_message' => $released > 0
                ? "The generation did not complete; {$released} credits were returned."
                : 'The generation did not complete.',
        ])->save();
    }
}
