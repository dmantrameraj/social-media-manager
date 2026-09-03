<?php

declare(strict_types=1);

namespace App\Domain\Media\Models;

use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Media\Enums\MediaStatus;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Policies\MediaPolicy;
use Database\Factories\MediaFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * A stored file.
 *
 * `disk` is per row, not global, so a migration from local storage to S3 can
 * proceed file by file with no flag day -- old and new coexist.
 *
 * @property int $tenant_id
 * @property MediaStatus $status
 * @property string|null $alt_text
 */
#[UseFactory(MediaFactory::class)]
#[UsePolicy(MediaPolicy::class)]
class Media extends Model
{
    use BelongsToTenant, HasUlids, SoftDeletes;

    /** @use HasFactory<MediaFactory> */
    use HasFactory;

    protected $table = 'media';

    protected $fillable = [
        'customer_id',
        'folder_id',
        'original_name',
        // Descriptive metadata a human writes, unlike everything in $guarded
        // below, which describes the stored bytes.
        'alt_text',
    ];

    /**
     * Everything describing the stored bytes is set by the upload pipeline,
     * never by request input -- a client-supplied path or size is an attack.
     */
    protected $guarded = [
        'id',
        'tenant_id',
        'disk',
        'path',
        'mime_type',
        'extension',
        'size_bytes',
        'checksum',
        'status',
        'thumbnail_path',
        'variants',
    ];

    protected function casts(): array
    {
        return [
            'status' => MediaStatus::class,
            'variants' => 'array',
            'meta' => 'array',
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'duration_seconds' => 'integer',
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

    // ---------------------------------------------------------------- relations

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(MediaFolder::class, 'folder_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    // ------------------------------------------------------------------ scopes

    /** @param  Builder<self>  $query */
    public function scopeReady(Builder $query): Builder
    {
        return $query->where('status', MediaStatus::Ready);
    }

    // ------------------------------------------------------------------- state

    public function isUsable(): bool
    {
        return $this->status->isUsable();
    }

    /**
     * Time-limited URL. Media lives on a private disk and is never served by
     * direct public path -- the caller must have passed a policy check first.
     */
    public function temporaryUrl(?int $seconds = null): string
    {
        return Storage::disk($this->disk)->temporaryUrl(
            $this->path,
            now()->addSeconds($seconds ?? (int) config('media.signed_url_ttl', 300)),
        );
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    /**
     * Does this file need alt text that it does not have?
     *
     * Only images and video are asked for it. A PDF's accessibility is a
     * property of the document itself, and prompting for a description of one
     * trains people to type something meaningless to clear the warning.
     */
    public function needsAltText(): bool
    {
        return ($this->isImage() || $this->isVideo())
            && trim((string) $this->alt_text) === '';
    }

    /**
     * What a screen reader should announce.
     *
     * Falls back to the filename only so the element is not silent; a filename
     * is not a description, which is why needsAltText() exists to surface the
     * gap rather than letting this quietly paper over it.
     */
    public function describedAs(): string
    {
        $alt = trim((string) $this->alt_text);

        return $alt !== '' ? $alt : 'Attachment: '.$this->original_name;
    }

    public function isVideo(): bool
    {
        return str_starts_with($this->mime_type, 'video/');
    }
}
