<?php

declare(strict_types=1);

namespace App\Domain\AI\Models;

use App\Domain\Customers\Models\Customer;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\AutopilotSettingFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Per-brand autopilot configuration.
 *
 * @property int $tenant_id
 * @property bool $enabled
 * @property ?Carbon $next_run_at
 */
#[UseFactory(AutopilotSettingFactory::class)]
class AutopilotSetting extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = ['posts_per_week', 'platforms', 'themes'];

    /** enabled and the run clock are lifecycle-owned, not form fields. */
    protected $guarded = ['id', 'tenant_id', 'customer_id', 'enabled', 'last_run_at', 'next_run_at'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'platforms' => 'array',
            'themes' => 'array',
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Brands whose next run has come around.
     *
     * @param  Builder<self>  $query
     */
    public function scopeDue(Builder $query, ?Carbon $at = null): Builder
    {
        $now = $at ?? now();

        return $query
            ->where('enabled', true)
            ->where(function (Builder $q) use ($now): void {
                // A brand that has never run is due immediately.
                $q->whereNull('next_run_at')->orWhere('next_run_at', '<=', $now);
            });
    }

    /**
     * Spread runs evenly across the week rather than firing a whole week's
     * content at once -- a client seeing seven drafts appear in one minute
     * reads as a malfunction.
     */
    public function intervalDays(): float
    {
        $perWeek = max(1, (int) $this->posts_per_week);

        return 7 / $perWeek;
    }

    public function scheduleNextRun(?Carbon $from = null): void
    {
        $base = $from ?? now();

        $this->forceFill([
            'last_run_at' => $base,
            'next_run_at' => $base->copy()->addMinutes((int) round($this->intervalDays() * 24 * 60)),
        ])->save();
    }
}
