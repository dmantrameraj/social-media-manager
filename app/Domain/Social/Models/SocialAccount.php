<?php

declare(strict_types=1);

namespace App\Domain\Social\Models;

use App\Domain\Customers\Models\Customer;
use App\Domain\Social\DTO\CapabilitySet;
use App\Domain\Social\Enums\AccountHealth;
use App\Domain\Social\Enums\AccountStatus;
use App\Domain\Social\Enums\SocialAccountType;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\SocialAccountFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One publishable destination: a Page, an IG Business account, a channel.
 *
 * Deliberately NOT soft-deleted -- a soft-deleted row would collide with
 * UNIQUE (tenant_id, provider_key, external_id) on reconnect, which is the
 * common path. Disconnecting sets the status and clears the token instead.
 *
 * @property int $tenant_id
 * @property AccountStatus $status
 * @property AccountHealth $health
 * @property SocialAccountType $account_type
 * @property-read SocialConnection|null $socialConnection
 */
#[UseFactory(SocialAccountFactory::class)]
class SocialAccount extends Model
{
    use BelongsToTenant, HasFactory, HasUlids;

    protected $guarded = ['*'];

    protected $hidden = ['page_access_token'];

    protected function casts(): array
    {
        return [
            'account_type' => SocialAccountType::class,
            'status' => AccountStatus::class,
            'health' => AccountHealth::class,
            'capabilities' => 'array',
            'scopes' => 'array',
            'meta' => 'array',
            'token_expires_at' => 'datetime',
            'last_published_at' => 'datetime',
            'last_error_at' => 'datetime',
            'page_access_token' => 'encrypted',
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

    /**
     * NOT named connection(): Eloquent's Model::$connection holds the database
     * connection name, so a relation of that name is shadowed and silently
     * returns a string instead of the model.
     */
    /** @return BelongsTo<SocialConnection, $this> */
    public function socialConnection(): BelongsTo
    {
        return $this->belongsTo(SocialConnection::class, 'social_connection_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @param  Builder<self>  $query */
    public function scopePublishable(Builder $query): Builder
    {
        return $query->where('status', AccountStatus::Active->value);
    }

    public function capabilitySet(): CapabilitySet
    {
        return CapabilitySet::fromArray((array) ($this->capabilities ?? []));
    }

    /**
     * Publishing requires BOTH the account and its connection to be usable --
     * an active account behind an expired grant cannot post.
     */
    public function canPublish(): bool
    {
        return $this->status->canPublish()
            && $this->socialConnection?->canPublish() === true;
    }

    /**
     * The token publishing should actually use. Facebook Pages have their own,
     * distinct from the user token that discovered them.
     */
    public function publishingToken(): ?string
    {
        return $this->page_access_token ?? $this->socialConnection?->access_token;
    }

    public function supports(string $feature): bool
    {
        return $this->capabilitySet()->supports($feature);
    }
}
