<?php

declare(strict_types=1);

namespace App\Domain\AI\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Cached credit balance for a tenant.
 *
 * `balance` is a PROJECTION of ai_credit_transactions, never the source of
 * truth. Nothing outside the ledger service writes to it, and a reconciliation
 * command recomputes it from the ledger and reports drift.
 * See docs/08-AI-ARCHITECTURE.md §5.
 *
 * @property int $tenant_id
 * @property int $balance
 * @property int $reserved
 * @property int $monthly_allowance
 * @property ?Carbon $period_start
 * @property ?Carbon $period_end
 * @property bool $rollover_enabled
 * @property ?int $rollover_cap
 */
class AiCreditAccount extends Model
{
    use BelongsToTenant, HasFactory;

    /**
     * Nothing here is mass-assignable. Every mutation goes through the ledger
     * service so it is always paired with a transaction row.
     */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'balance' => 'integer',
            'reserved' => 'integer',
            'monthly_allowance' => 'integer',
            'rollover_cap' => 'integer',
            'rollover_enabled' => 'boolean',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(AiCreditTransaction::class);
    }

    /**
     * Credits actually spendable right now: the balance less anything held
     * against an in-flight generation.
     */
    public function available(): int
    {
        return $this->balance - $this->reserved;
    }

    public function hasAvailable(int $credits): bool
    {
        return $this->available() >= $credits;
    }

    public function periodHasElapsed(?Carbon $at = null): bool
    {
        return $this->period_end !== null
            && $this->period_end->lessThanOrEqualTo($at ?? now());
    }
}
