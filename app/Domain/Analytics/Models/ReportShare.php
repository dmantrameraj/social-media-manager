<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Models;

use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\ReportShareFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A read-only report link an agency can send to a client.
 *
 * The only unauthenticated surface in the product that shows tenant data, so
 * it is built the way oauth_states is: the token is unguessable, stored only
 * as a hash, bounded by an expiry, and revocable.
 *
 * @property int $tenant_id
 * @property int $customer_id
 * @property string $token_hash
 * @property Carbon $window_from
 * @property Carbon $window_to
 * @property Carbon $expires_at
 * @property Carbon|null $revoked_at
 * @property int $view_count
 * @property-read Customer|null $customer
 * @property-read User|null $creator
 */
#[UseFactory(ReportShareFactory::class)]
class ReportShare extends Model
{
    use BelongsToTenant, HasFactory, HasUlids;

    /**
     * Nothing is fillable. Every column here bounds what a leaked link can
     * reach -- the window, the expiry, the brand -- so none of them may be set
     * from request input without passing the controller's own checks.
     *
     * @var list<string>
     */
    protected $fillable = [];

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'window_from' => 'datetime',
            'window_to' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'view_count' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * A fresh secret, and the hash to store beside it.
     *
     * 256 bits from a CSPRNG. The plaintext is returned once, to be put in a
     * link and never persisted -- the same shape OAuthStateService uses,
     * because the requirement is the same: a database read must not yield
     * something that works.
     *
     * @return array{token: string, hash: string}
     */
    public static function newToken(): array
    {
        $token = Str::random(64);

        return ['token' => $token, 'hash' => hash('sha256', $token)];
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Both conditions, always checked together.
     *
     * Expiry and revocation are different decisions and either one is fatal,
     * so callers ask this rather than remembering to check two things.
     */
    public function isViewable(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired();
    }

    /** @param  Builder<self>  $query */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')->where('expires_at', '>', now());
    }
}
