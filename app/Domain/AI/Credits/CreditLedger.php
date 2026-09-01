<?php

declare(strict_types=1);

namespace App\Domain\AI\Credits;

use App\Domain\AI\Credits\Enums\CreditTransactionType;
use App\Domain\AI\Credits\Exceptions\InsufficientCredits;
use App\Domain\AI\Models\AiCreditAccount;
use App\Domain\AI\Models\AiCreditTransaction;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The only thing permitted to move credits.
 *
 * `ai_credit_transactions` is the source of truth; `ai_credit_accounts.balance`
 * is a cache kept in step here. Nothing else writes to either.
 *
 * The reserve/commit/release triple exists because AI calls are asynchronous
 * and can fail after the request is sent. Charging on completion allows
 * overspend under concurrency; charging up front overcharges on failure.
 * See docs/08-AI-ARCHITECTURE.md §5.
 */
final class CreditLedger
{
    public function accountFor(Tenant $tenant): AiCreditAccount
    {
        return AiCreditAccount::query()
            ->forTenant($tenant)
            ->firstOrFail();
    }

    /** Credits added: plan allowance, admin grant, purchased pack. */
    public function grant(
        Tenant $tenant,
        int $credits,
        string $note,
        ?Model $reference = null,
        ?string $idempotencyKey = null,
        ?int $userId = null,
    ): AiCreditTransaction {
        $this->assertPositive($credits);

        return $this->write(
            $tenant,
            CreditTransactionType::Grant,
            $credits,
            $note,
            $reference,
            $idempotencyKey,
            $userId,
        );
    }

    /**
     * Hold credits against an in-flight generation.
     *
     * Reduces available (balance - reserved) without reducing the balance, so
     * two concurrent requests cannot both spend the same credits.
     *
     * @throws InsufficientCredits
     */
    public function reserve(
        Tenant $tenant,
        int $credits,
        string $note = 'Reserved for AI generation',
        ?Model $reference = null,
        ?string $idempotencyKey = null,
        ?int $userId = null,
    ): AiCreditTransaction {
        $this->assertPositive($credits);

        return DB::transaction(function () use (
            $tenant, $credits, $note, $reference, $idempotencyKey, $userId
        ): AiCreditTransaction {
            // Row lock: the check and the write must not interleave with a
            // concurrent reservation, or both would see enough credit.
            $account = $this->lockAccount($tenant);

            if (! $account->hasAvailable($credits)) {
                throw new InsufficientCredits($credits, $account->available());
            }

            $account->reserved += $credits;
            $account->save();

            return $this->record(
                $account, CreditTransactionType::Reserve, -$credits,
                $account->balance, $note, $reference, $idempotencyKey, $userId,
            );
        });
    }

    /**
     * Convert a reservation into an actual charge.
     *
     * The final cost may differ from the estimate, so it is passed explicitly
     * rather than assumed equal to the reservation.
     */
    public function commit(
        Tenant $tenant,
        int $reservedCredits,
        int $actualCredits,
        string $note = 'AI generation',
        ?Model $reference = null,
        ?string $idempotencyKey = null,
        ?int $userId = null,
    ): AiCreditTransaction {
        return DB::transaction(function () use (
            $tenant, $reservedCredits, $actualCredits, $note, $reference, $idempotencyKey, $userId
        ): AiCreditTransaction {
            $account = $this->lockAccount($tenant);

            $account->reserved = max(0, $account->reserved - $reservedCredits);
            $account->balance -= $actualCredits;
            $account->save();

            return $this->record(
                $account, CreditTransactionType::Consume, -$actualCredits,
                $account->balance, $note, $reference, $idempotencyKey, $userId,
            );
        });
    }

    /** Return an unspent reservation -- a failed generation is not charged. */
    public function release(
        Tenant $tenant,
        int $credits,
        string $note = 'Reservation released',
        ?Model $reference = null,
        ?string $idempotencyKey = null,
    ): AiCreditTransaction {
        return DB::transaction(function () use (
            $tenant, $credits, $note, $reference, $idempotencyKey
        ): AiCreditTransaction {
            $account = $this->lockAccount($tenant);

            $account->reserved = max(0, $account->reserved - $credits);
            $account->save();

            return $this->record(
                $account, CreditTransactionType::Release, $credits,
                $account->balance, $note, $reference, $idempotencyKey, null,
            );
        });
    }

    /**
     * Super Admin correction. Always carries a reason, and always leaves a
     * transaction -- there is no path that edits the balance directly.
     */
    public function adjust(
        Tenant $tenant,
        int $delta,
        string $reason,
        ?int $adminUserId = null,
    ): AiCreditTransaction {
        return $this->write(
            $tenant,
            CreditTransactionType::Adjustment,
            $delta,
            $reason,
            null,
            null,
            $adminUserId,
        );
    }

    /**
     * Monthly period rollover.
     *
     * Idempotent per period: running twice in the same window is a no-op, so a
     * retried scheduled command cannot double-grant.
     */
    public function resetPeriod(Tenant $tenant): ?AiCreditTransaction
    {
        return DB::transaction(function () use ($tenant): ?AiCreditTransaction {
            $account = $this->lockAccount($tenant);

            if (! $account->periodHasElapsed()) {
                return null;
            }

            $unused = max(0, $account->balance);
            $rollover = $account->rollover_enabled
                ? min($unused, $account->rollover_cap ?? $unused)
                : 0;

            $newBalance = $account->monthly_allowance + $rollover;
            $delta = $newBalance - $account->balance;

            $account->balance = $newBalance;
            $account->period_start = $account->period_end;
            $account->period_end = $account->period_start->copy()->addMonthNoOverflow();
            $account->save();

            return $this->record(
                $account, CreditTransactionType::Reset, $delta, $newBalance,
                'Monthly credit reset', null,
                // One reset per tenant per period, enforced by the unique key.
                sprintf('reset:%d:%s', $tenant->getKey(), $account->period_start->format('Y-m-d')),
                null,
            );
        });
    }

    /**
     * Recompute the cached balance from the ledger and report drift.
     *
     * @return array{balance: int, ledger: int, drift: int}
     */
    public function reconcile(Tenant $tenant): array
    {
        $account = $this->accountFor($tenant);

        $ledger = (int) AiCreditTransaction::query()
            ->where('ai_credit_account_id', $account->getKey())
            ->whereIn('type', [
                CreditTransactionType::Grant->value,
                CreditTransactionType::Reset->value,
                CreditTransactionType::Consume->value,
                CreditTransactionType::Refund->value,
                CreditTransactionType::Adjustment->value,
            ])
            ->sum('amount');

        return [
            'balance' => $account->balance,
            'ledger' => $ledger,
            'drift' => $account->balance - $ledger,
        ];
    }

    // ------------------------------------------------------------- internals

    private function write(
        Tenant $tenant,
        CreditTransactionType $type,
        int $amount,
        string $note,
        ?Model $reference,
        ?string $idempotencyKey,
        ?int $userId,
    ): AiCreditTransaction {
        return DB::transaction(function () use (
            $tenant, $type, $amount, $note, $reference, $idempotencyKey, $userId
        ): AiCreditTransaction {
            $account = $this->lockAccount($tenant);

            $account->balance += $amount;
            $account->save();

            return $this->record(
                $account, $type, $amount, $account->balance,
                $note, $reference, $idempotencyKey, $userId,
            );
        });
    }

    private function record(
        AiCreditAccount $account,
        CreditTransactionType $type,
        int $amount,
        int $balanceAfter,
        string $note,
        ?Model $reference,
        ?string $idempotencyKey,
        ?int $userId,
    ): AiCreditTransaction {
        $transaction = new AiCreditTransaction;
        $transaction->tenant_id = $account->tenant_id;
        $transaction->ai_credit_account_id = $account->getKey();
        $transaction->type = $type;
        $transaction->amount = $amount;
        $transaction->balance_after = $balanceAfter;
        $transaction->reference_type = $reference !== null ? $reference::class : null;
        $transaction->reference_id = $reference?->getKey();
        $transaction->idempotency_key = $idempotencyKey;
        $transaction->user_id = $userId;
        $transaction->note = $note;
        $transaction->save();

        return $transaction;
    }

    private function lockAccount(Tenant $tenant): AiCreditAccount
    {
        return AiCreditAccount::query()
            ->forTenant($tenant)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertPositive(int $credits): void
    {
        if ($credits <= 0) {
            throw new \InvalidArgumentException('Credit amounts must be positive.');
        }
    }
}
