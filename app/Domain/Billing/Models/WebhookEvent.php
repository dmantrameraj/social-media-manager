<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Provider-agnostic webhook inbox.
 *
 * The unique key on (provider, event_id) IS the deduplication guarantee: a
 * gateway retrying a delivery cannot cause the same event to be processed
 * twice, no matter how the handler behaves.
 *
 * Not tenant-owned: a webhook arrives before we know which tenant it concerns.
 *
 * @property ?Carbon $processed_at
 */
class WebhookEvent extends Model
{
    use HasFactory;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'signature_verified' => 'boolean',
            'processed_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    public function isProcessed(): bool
    {
        return $this->processed_at !== null;
    }
}
