<?php

declare(strict_types=1);

namespace App\Domain\Social\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * An agency's own developer app credentials.
 *
 * Security rules for this model, all enforced and tested:
 *   - client_id, client_secret and extra are encrypted casts
 *   - all three are $hidden, so no serialisation can leak them
 *   - write-only in the UI: an empty submitted value means "unchanged"
 *   - Super Admin has NO screen that displays them, and none may be added
 *
 * See docs/05-SOCIAL-PROVIDERS.md §6 and docs/10-SECURITY.md §2.
 *
 * @property int $tenant_id
 * @property string $provider_key
 * @property string $label
 * @property bool $is_active
 * @property ?Carbon $verified_at
 * @property ?string $last_verify_error
 */
class SocialAppCredential extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $guarded = ['*'];

    /**
     * client_id is hidden alongside the secret. It is not itself a credential,
     * but publishing it lets an attacker construct a convincing OAuth consent
     * screen in the agency's name.
     */
    protected $hidden = ['client_id', 'client_secret', 'extra'];

    protected function casts(): array
    {
        return [
            'client_id' => 'encrypted',
            'client_secret' => 'encrypted',
            'extra' => 'encrypted:array',
            'is_active' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    public function isUsable(): bool
    {
        return $this->is_active && $this->deleted_at === null;
    }

    /**
     * Safe projection for any UI or API response.
     *
     * Existence, provider, label and verification state only -- never a value,
     * not even masked, since a mask still confirms length.
     *
     * @return array<string, mixed>
     */
    public function toSafeArray(): array
    {
        return [
            'id' => $this->getKey(),
            'provider_key' => $this->provider_key,
            'label' => $this->label,
            'is_active' => $this->is_active,
            'verified_at' => $this->verified_at?->toIso8601String(),
            'last_verify_error' => $this->last_verify_error,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
