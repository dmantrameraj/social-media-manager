<?php

declare(strict_types=1);

namespace App\Domain\Social\Models;

use App\Domain\Customers\Models\Customer;
use App\Domain\Social\Enums\ConnectionStatus;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\SocialConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One OAuth grant.
 *
 * Holds the tokens; social_accounts hold the destinations derived from it.
 * Keeping them apart means one Meta grant yielding six Pages stores its tokens
 * once, and reconnecting updates one row rather than six.
 *
 * @property int $tenant_id
 * @property ConnectionStatus $status
 * @property ?Carbon $expires_at
 * @property ?array<int, string> $scopes
 */
#[UseFactory(SocialConnectionFactory::class)]
class SocialConnection extends Model
{
    use BelongsToTenant, HasFactory, HasUlids, SoftDeletes;

    /** Tokens are never mass-assignable from anywhere. */
    protected $guarded = ['*'];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'status' => ConnectionStatus::class,
            'scopes' => 'array',
            'meta' => 'array',
            'expires_at' => 'datetime',
            'refresh_expires_at' => 'datetime',
            'last_refreshed_at' => 'datetime',
            'last_checked_at' => 'datetime',
            // Encrypted at rest with APP_KEY. Also hidden above, and a test
            // asserts they never appear in serialised output.
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
        ];
    }

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function credential(): BelongsTo
    {
        return $this->belongsTo(SocialAppCredential::class, 'social_app_credential_id');
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    /** @param  Builder<self>  $query */
    public function scopeNeedingRefresh(Builder $query, ?int $leadSeconds = null): Builder
    {
        $lead = $leadSeconds ?? (int) config('social.refresh_lead_time', 86400);

        return $query
            ->where('status', ConnectionStatus::Active->value)
            ->whereNotNull('refresh_token')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->addSeconds($lead));
    }

    public function canPublish(): bool
    {
        return $this->status->canPublish();
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
