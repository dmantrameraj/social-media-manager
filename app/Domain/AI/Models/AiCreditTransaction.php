<?php

declare(strict_types=1);

namespace App\Domain\AI\Models;

use App\Domain\AI\Credits\Enums\CreditTransactionType;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only credit ledger. The balance is SUM(amount) over these rows.
 *
 * No updated_at, no soft delete, and no update path: history that can be
 * rewritten is not an audit trail. See docs/08-AI-ARCHITECTURE.md §5.
 *
 * @property int $tenant_id
 * @property CreditTransactionType $type
 * @property int $amount
 * @property int $balance_after
 */
class AiCreditTransaction extends Model
{
    use BelongsToTenant, HasFactory, HasUlids;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'type' => CreditTransactionType::class,
            'amount' => 'integer',
            'balance_after' => 'integer',
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(AiCreditAccount::class, 'ai_credit_account_id');
    }

    /**
     * Ledger rows are immutable. Blocking this at the model level means a
     * careless save() in a future service fails loudly rather than corrupting
     * the audit trail.
     */
    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new \RuntimeException(
                'ai_credit_transactions is append-only. Correct a mistake with a '
                .'compensating adjustment transaction, never by editing history.'
            );
        });

        static::deleting(static function (): never {
            throw new \RuntimeException('ai_credit_transactions rows cannot be deleted.');
        });
    }
}
