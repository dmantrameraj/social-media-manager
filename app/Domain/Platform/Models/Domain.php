<?php

declare(strict_types=1);

namespace App\Domain\Platform\Models;

use App\Domain\Platform\Enums\DomainType;
use App\Domain\Platform\Enums\SslStatus;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\DomainFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A hostname that reaches one agency's client portal.
 *
 * The table shipped in Phase 1 as a schema stub with nothing attached. Its own
 * migration explains the one unusual thing about it: hostname is globally
 * unique rather than unique per tenant, "because host-to-tenant resolution
 * demands it" -- a hostname maps to exactly one agency or it maps to nothing.
 *
 * @property int $tenant_id
 * @property string $hostname
 * @property DomainType $type
 * @property bool $is_primary
 * @property string|null $verification_token
 * @property Carbon|null $verified_at
 * @property SslStatus|null $ssl_status
 */
#[UseFactory(DomainFactory::class)]
class Domain extends Model
{
    use BelongsToTenant, HasFactory;

    /**
     * Nothing is fillable. hostname and tenant_id together decide which
     * agency a request belongs to, so neither may be set from request input
     * without passing through the controller's own checks.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => DomainType::class,
            'ssl_status' => SslStatus::class,
            'is_primary' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * The TXT value an agency must publish.
     *
     * Random and per-domain: a predictable token would let somebody claim a
     * hostname by guessing what to publish, and a shared one would let a
     * verified agency prove any hostname.
     */
    public static function newVerificationToken(): string
    {
        return 'smm-verify-'.Str::lower(Str::random(32));
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /**
     * Can this hostname actually serve a portal?
     *
     * Verification and a certificate are different things: DNS proves the
     * agency owns the name, TLS decides whether a browser will open it. Both
     * are required, and neither implies the other.
     */
    public function isUsable(): bool
    {
        return $this->isVerified() && $this->ssl_status === SslStatus::Active;
    }

    /**
     * Domains that may resolve a request to a tenant.
     *
     * Verified only. An unverified row is a CLAIM, and resolving on a claim
     * would let anybody point DNS at us and be served another agency's portal.
     *
     * @param  Builder<self>  $query
     */
    public function scopeResolvable(Builder $query): Builder
    {
        return $query->whereNotNull('verified_at');
    }
}
