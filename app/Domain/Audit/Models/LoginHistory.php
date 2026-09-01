<?php

declare(strict_types=1);

namespace App\Domain\Audit\Models;

use App\Domain\Audit\Enums\LoginEvent;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Authentication events for both guards.
 *
 * Append-only. Deliberately NOT a BelongsToTenant model: a failed login happens
 * before any tenant is resolved, and the row must still be written.
 *
 * NEVER stores the attempted password, or any part of it.
 *
 * @property LoginEvent $event
 */
class LoginHistory extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'login_histories';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'event' => LoginEvent::class,
            'created_at' => 'datetime',
        ];
    }

    public function authenticatable(): MorphTo
    {
        return $this->morphTo();
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new \RuntimeException('login_histories is append-only.');
        });
    }
}
