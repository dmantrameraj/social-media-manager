<?php

declare(strict_types=1);

namespace App\Domain\Media\Models;

use App\Domain\Customers\Models\Customer;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Policies\MediaFolderPolicy;
use Database\Factories\MediaFolderFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $tenant_id
 */
#[UseFactory(MediaFolderFactory::class)]
#[UsePolicy(MediaFolderPolicy::class)]
class MediaFolder extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = [
        'customer_id',
        'parent_id',
        'name',
    ];

    /** system_key marks seeded folders, which must not be renamed or deleted. */
    protected $guarded = [
        'id',
        'tenant_id',
        'system_key',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function media(): HasMany
    {
        return $this->hasMany(Media::class, 'folder_id');
    }

    public function isSystemFolder(): bool
    {
        return $this->system_key !== null;
    }
}
