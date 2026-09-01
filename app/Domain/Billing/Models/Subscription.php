<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Domain\Billing\Enums\PaymentGateway;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $tenant_id
 * @property SubscriptionStatus $status
 * @property PaymentGateway $gateway
 * @property ?Carbon $trial_ends_at
 * @property ?Carbon $current_period_end
 * @property ?Carbon $grace_ends_at
 */
class Subscription extends Model
{
    use BelongsToTenant, HasFactory, HasUlids, SoftDeletes;

    /** Lifecycle-owned throughout; nothing here is set from request input. */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'gateway' => PaymentGateway::class,
            'trial_ends_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'grace_ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'ends_at' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'meta' => 'array',
        ];
    }

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    /** @param  Builder<self>  $query */
    public function scopeActiveish(Builder $query): Builder
    {
        return $query->whereIn('status', [
            SubscriptionStatus::Trialing->value,
            SubscriptionStatus::Active->value,
            SubscriptionStatus::PastDue->value,
            SubscriptionStatus::Grace->value,
        ]);
    }

    public function grantsEntitlements(): bool
    {
        return $this->status->grantsEntitlements();
    }
}
