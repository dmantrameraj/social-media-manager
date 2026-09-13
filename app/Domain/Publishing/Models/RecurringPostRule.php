<?php

declare(strict_types=1);

namespace App\Domain\Publishing\Models;

use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Publishing\Enums\RecurrenceFrequency;
use App\Domain\Social\Models\SocialAccount;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\RecurringPostRuleFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * "Post this every Tuesday at nine."
 *
 * A template plus a cadence. It publishes nothing itself: MaterialiseRecurring
 * PostsService turns it into concrete posts a bounded window ahead, and those
 * posts take the same workflow, approval gate and plan limit as any other. A
 * rule that published directly would be a second publishing path, and the
 * second one is always the one that forgets a rule the first one enforces.
 *
 * @property int $tenant_id
 * @property RecurrenceFrequency $frequency
 * @property int $interval
 * @property ?array<int, int> $weekdays
 * @property ?int $day_of_month
 * @property string $timezone
 * @property Carbon $starts_on
 * @property ?Carbon $ends_on
 * @property ?Carbon $materialised_through
 * @property bool $is_active
 */
#[UseFactory(RecurringPostRuleFactory::class)]
class RecurringPostRule extends Model
{
    use BelongsToTenant, HasFactory, HasUlids, SoftDeletes;

    protected $guarded = ['id', 'tenant_id'];

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    protected function casts(): array
    {
        return [
            'frequency' => RecurrenceFrequency::class,
            'weekdays' => 'array',
            'interval' => 'integer',
            'day_of_month' => 'integer',
            'is_active' => 'boolean',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'materialised_through' => 'date',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Where each occurrence publishes.
     *
     * @return BelongsToMany<SocialAccount, $this>
     */
    public function accounts(): BelongsToMany
    {
        return $this->belongsToMany(
            SocialAccount::class,
            'recurring_post_rule_accounts',
            'recurring_post_rule_id',
            'social_account_id',
        );
    }

    /**
     * The posts this rule has produced.
     *
     * @return HasMany<Post, $this>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class)->orderByDesc('occurrence_date');
    }

    /**
     * Rules the materialiser should look at.
     *
     * @param  Builder<self>  $query
     */
    public function scopeMaterialisable(Builder $query, ?Carbon $on = null): Builder
    {
        $on ??= Carbon::now();

        return $query
            ->where('is_active', true)
            ->whereDate('starts_on', '<=', $on->copy()->addDays(
                (int) config('publishing.recurrence_horizon_days', 60),
            ))
            // A rule that has already ended is finished, not paused.
            ->where(fn (Builder $q) => $q
                ->whereNull('ends_on')
                ->orWhereDate('ends_on', '>=', $on));
    }

    /**
     * Has this rule run its course?
     *
     * Distinct from being switched off: an ended rule cannot be resumed by
     * flipping is_active, and the screen says so rather than offering a button
     * that does nothing.
     */
    public function hasEnded(?Carbon $on = null): bool
    {
        $on ??= Carbon::now();

        return $this->ends_on !== null && $this->ends_on->lt($on->copy()->startOfDay());
    }

    /** A sentence a person can read, for the list. */
    public function cadenceSummary(): string
    {
        $every = $this->interval > 1 ? "every {$this->interval} " : 'every ';

        $unit = match ($this->frequency) {
            RecurrenceFrequency::Daily => $this->interval > 1 ? 'days' : 'day',
            RecurrenceFrequency::Weekly => $this->interval > 1 ? 'weeks' : 'week',
            RecurrenceFrequency::Monthly => $this->interval > 1 ? 'months' : 'month',
        };

        $detail = match ($this->frequency) {
            RecurrenceFrequency::Weekly => ' on '.collect($this->weekdays ?? [])
                ->map(fn (int $day): string => Carbon::now()->startOfWeek()->addDays($day - 1)->format('D'))
                ->implode(', '),
            RecurrenceFrequency::Monthly => ' on day '.$this->day_of_month,
            RecurrenceFrequency::Daily => '',
        };

        return ucfirst($every.$unit.$detail).' at '.Carbon::parse($this->time_of_day)->format('H:i');
    }
}
