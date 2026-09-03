<?php

declare(strict_types=1);

use App\Domain\Billing\Entitlements\EntitlementResolver;
use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Media\Enums\MediaStatus;
use App\Domain\Media\Jobs\GenerateMediaVariants;
use App\Domain\Media\Models\Media;
use App\Domain\Media\Services\StoreMediaService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    [$this->tenant, $this->owner] = provisionTenant();

    actingForTenant($this->tenant);

    $this->brand = Customer::factory()->create(['tenant_id' => $this->tenant->getKey()]);

    Storage::fake('local');
});

/** A real PNG on the fake disk, so GD has something genuine to decode. */
function storedImage(Customer $brand, int $width = 1600, int $height = 1200): Media
{
    $media = Media::factory()->forCustomer($brand)->create([
        'status' => MediaStatus::Processing,
        'mime_type' => 'image/png',
        'extension' => 'png',
        'path' => 'media/'.$brand->tenant_id.'/'.$brand->getKey().'/source.png',
        'width' => null,
        'height' => null,
    ]);

    // Drawn here rather than via UploadedFile::fake(): that writes to the system
    // temp directory and the file is gone by the time the job reads it, which
    // fails as "no such file" and looks like a bug in the job.
    $gd = imagecreatetruecolor($width, $height);
    imagefilledrectangle($gd, 0, 0, $width - 1, $height - 1, imagecolorallocate($gd, 40, 90, 160));

    ob_start();
    imagepng($gd);
    $bytes = (string) ob_get_clean();
    imagedestroy($gd);

    Storage::disk('local')->put($media->path, $bytes);

    return $media;
}

// --------------------------------------------------------------- the core gap

it('moves an image from processing to ready', function (): void {
    /*
     | The bug this job exists for. StoreMediaService marks every image
     | `processing` and nothing moved it on, so no uploaded image was ever
     | offered by the composer or accepted by publishing.
     */
    $media = storedImage($this->brand);

    (new GenerateMediaVariants($media->getKey()))->handle(app(EntitlementResolver::class));

    expect($media->fresh()->status)->toBe(MediaStatus::Ready)
        ->and($media->fresh()->isUsable())->toBeTrue();
});

it('writes a thumbnail and a preview', function (): void {
    $media = storedImage($this->brand);

    (new GenerateMediaVariants($media->getKey()))->handle(app(EntitlementResolver::class));

    $fresh = $media->fresh();

    expect($fresh->variants)->toHaveKeys(['thumb', 'preview'])
        ->and($fresh->thumbnail_path)->toBe($fresh->variants['thumb']);

    Storage::disk('local')->assertExists($fresh->variants['thumb']);
    Storage::disk('local')->assertExists($fresh->variants['preview']);
});

it('records the source dimensions', function (): void {
    $media = storedImage($this->brand, 1600, 1200);

    (new GenerateMediaVariants($media->getKey()))->handle(app(EntitlementResolver::class));

    expect($media->fresh()->width)->toBe(1600)
        ->and($media->fresh()->height)->toBe(1200);
});

// ------------------------------------------------------------------- sizing

it('scales down without enlarging a small image', function (): void {
    // Upscaling invents detail and costs bytes; a 64px logo must stay 64px.
    $media = storedImage($this->brand, 64, 64);

    (new GenerateMediaVariants($media->getKey()))->handle(app(EntitlementResolver::class));

    $thumb = Storage::disk('local')->get($media->fresh()->variants['thumb']);

    [$width, $height] = getimagesizefromstring($thumb);

    expect($width)->toBe(64)->and($height)->toBe(64);
});

it('fits a large image inside the thumbnail box', function (): void {
    $media = storedImage($this->brand, 1600, 1200);

    (new GenerateMediaVariants($media->getKey()))->handle(app(EntitlementResolver::class));

    $thumb = Storage::disk('local')->get($media->fresh()->variants['thumb']);

    [$width, $height] = getimagesizefromstring($thumb);

    // 320 box, aspect preserved: the long edge lands on the limit.
    expect($width)->toBe(320)
        ->and($height)->toBeLessThanOrEqual(320);
});

it('re-encodes derivatives as webp whatever went in', function (): void {
    $media = storedImage($this->brand);

    (new GenerateMediaVariants($media->getKey()))->handle(app(EntitlementResolver::class));

    $thumb = Storage::disk('local')->get($media->fresh()->variants['thumb']);

    // Re-encoding is also what strips EXIF and any smuggled trailing payload.
    expect(getimagesizefromstring($thumb)['mime'])->toBe('image/webp');
});

// -------------------------------------------------------------- book-keeping

it('counts variant bytes against the storage quota', function (): void {
    /*
     | Variants are real files on the same disk. Counted separately from
     | size_bytes, which is the uploaded file's own size and is shown to users.
     */
    $media = storedImage($this->brand);

    (new GenerateMediaVariants($media->getKey()))->handle(app(EntitlementResolver::class));

    $fresh = $media->fresh();

    $onDisk = strlen((string) Storage::disk('local')->get($fresh->variants['thumb']))
        + strlen((string) Storage::disk('local')->get($fresh->variants['preview']));

    expect($fresh->variant_bytes)->toBe($onDisk)
        ->and($fresh->size_bytes)->not->toBe($onDisk);

    // The ENTITLEMENT key, not the usage name: currentUsage() looks the
    // definition up to find which counter to run, and an unknown key falls
    // through to zero rather than erroring.
    expect(app(EntitlementResolver::class)->currentUsage($this->tenant, 'storage.max_bytes'))
        ->toBe($fresh->size_bytes + $fresh->variant_bytes);
});

// -------------------------------------------------------------- failure paths

it('marks a row failed rather than leaving it processing for ever', function (): void {
    // `processing` reads as "nearly there" in every list, so a permanent one is
    // a file the user waits on indefinitely.
    $media = Media::factory()->forCustomer($this->brand)->create([
        'status' => MediaStatus::Processing,
        'mime_type' => 'image/png',
        'path' => 'media/missing/nothing-here.png',
    ]);

    (new GenerateMediaVariants($media->getKey()))->failed(new RuntimeException('boom'));

    expect($media->fresh()->status)->toBe(MediaStatus::Failed);
});

it('throws when the source file is gone', function (): void {
    $media = Media::factory()->forCustomer($this->brand)->create([
        'status' => MediaStatus::Processing,
        'mime_type' => 'image/png',
        'path' => 'media/missing/nothing-here.png',
    ]);

    expect(fn () => (new GenerateMediaVariants($media->getKey()))->handle(app(EntitlementResolver::class)))
        ->toThrow(RuntimeException::class);
});

it('does nothing twice', function (): void {
    // A redelivered message must not double-count bytes or rewrite a finished row.
    $media = storedImage($this->brand);

    $job = new GenerateMediaVariants($media->getKey());
    $job->handle(app(EntitlementResolver::class));

    $afterFirst = $media->fresh();

    $job->handle(app(EntitlementResolver::class));

    expect($media->fresh()->variant_bytes)->toBe($afterFirst->variant_bytes)
        ->and($media->fresh()->updated_at->timestamp)->toBe($afterFirst->updated_at->timestamp);
});

it('ignores media deleted between upload and processing', function (): void {
    // Asynchronous by nature; a person changing their mind is not a failure.
    $media = storedImage($this->brand);
    $id = $media->getKey();
    $media->forceDelete();

    (new GenerateMediaVariants($id))->handle(app(EntitlementResolver::class));
})->throwsNoExceptions();

it('leaves a non-image alone', function (): void {
    $pdf = Media::factory()->forCustomer($this->brand)->create([
        'status' => MediaStatus::Ready,
        'mime_type' => 'application/pdf',
        'extension' => 'pdf',
    ]);

    (new GenerateMediaVariants($pdf->getKey()))->handle(app(EntitlementResolver::class));

    expect($pdf->fresh()->variants)->toBeNull();
});

// ------------------------------------------------------------------ dispatch

it('queues the job when an image is uploaded', function (): void {
    Queue::fake();

    app(StoreMediaService::class)->execute(
        $this->brand,
        $this->owner,
        UploadedFile::fake()->image('photo.jpg', 800, 600),
    );

    Queue::assertPushed(GenerateMediaVariants::class);
});

it('does not queue anything for a non-image', function (): void {
    Queue::fake();

    app(StoreMediaService::class)->execute(
        $this->brand,
        $this->owner,
        UploadedFile::fake()->create('brief.pdf', 12, 'application/pdf'),
    );

    Queue::assertNotPushed(GenerateMediaVariants::class);
});

it('queues onto the media queue', function (): void {
    // Image processing is slow and must not sit behind it in the default queue.
    expect((new GenerateMediaVariants(1))->queue)->toBe('media');
});
