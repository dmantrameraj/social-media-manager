<?php

declare(strict_types=1);

use App\Domain\Billing\Entitlements\EntitlementResolver;
use App\Domain\Billing\Entitlements\Exceptions\EntitlementExceeded;
use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Media\Enums\MediaStatus;
use App\Domain\Media\Exceptions\MediaRejected;
use App\Domain\Media\Models\Media;
use App\Domain\Media\Models\MediaFolder;
use App\Domain\Media\Services\StoreMediaService;
use App\Domain\Tenancy\Services\ProvisionTenantService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    seedPermissions();
    Storage::fake('local');

    $this->service = app(StoreMediaService::class);
    $this->owner = User::factory()->create();
    $this->tenant = app(ProvisionTenantService::class)->execute($this->owner, 'Bright Digital');
    $this->owner = $this->owner->fresh();

    givePlanLimit($this->tenant->getKey(), 'storage.max_bytes', 10_000_000);
    app(EntitlementResolver::class)->forget($this->tenant);

    actingForTenant($this->tenant);
    $this->brand = Customer::factory()->create(['tenant_id' => $this->tenant->getKey()]);
});

it('stores an image and records its metadata', function (): void {
    $file = UploadedFile::fake()->image('photo.jpg', 800, 600);

    $media = $this->service->execute($this->brand, $this->owner, $file);

    expect($media->tenant_id)->toBe($this->tenant->getKey())
        ->and($media->customer_id)->toBe($this->brand->getKey())
        ->and($media->mime_type)->toBe('image/jpeg')
        ->and($media->width)->toBe(800)
        ->and($media->height)->toBe(600)
        ->and($media->checksum)->toHaveLength(64)
        // Images need variants generating before they are usable.
        ->and($media->status)->toBe(MediaStatus::Processing);

    Storage::disk('local')->assertExists($media->path);
});

it('generates its own filename rather than trusting the client', function (): void {
    $file = UploadedFile::fake()->image('../../etc/passwd.jpg');

    $media = $this->service->execute($this->brand, $this->owner, $file);

    // The original name survives as metadata only; the stored path is ours.
    expect($media->path)->not->toContain('..')
        ->and($media->path)->toStartWith("media/{$this->tenant->getKey()}/{$this->brand->getKey()}/")
        ->and($media->path)->toEndWith('.jpg');
});

it('partitions stored files by tenant and brand', function (): void {
    $media = $this->service->execute(
        $this->brand, $this->owner, UploadedFile::fake()->image('a.png')
    );

    expect($media->path)->toContain("/{$this->tenant->getKey()}/{$this->brand->getKey()}/");
});

it('rejects a disallowed extension', function (): void {
    $file = UploadedFile::fake()->create('malware.exe', 10, 'application/octet-stream');

    expect(fn () => $this->service->execute($this->brand, $this->owner, $file))
        ->toThrow(MediaRejected::class);

    expect(Media::query()->count())->toBe(0);
});

it('rejects SVG while it is disabled', function (): void {
    $file = UploadedFile::fake()->create('logo.svg', 5, 'image/svg+xml');

    // SVG can carry script and is served same-origin.
    expect(fn () => $this->service->execute($this->brand, $this->owner, $file))
        ->toThrow(MediaRejected::class);
});

it('rejects a file whose contents contradict its extension', function (): void {
    // A PDF wearing a .jpg extension: each list would pass it alone.
    $file = UploadedFile::fake()->create('sneaky.jpg', 10, 'application/pdf');

    expect(fn () => $this->service->execute($this->brand, $this->owner, $file))
        ->toThrow(MediaRejected::class);
});

it('rejects a file over the size cap', function (): void {
    config()->set('media.max_upload_bytes', 1024);

    $file = UploadedFile::fake()->create('big.pdf', 50, 'application/pdf');

    expect(fn () => $this->service->execute($this->brand, $this->owner, $file))
        ->toThrow(MediaRejected::class);
});

it('enforces the storage quota before writing bytes', function (): void {
    givePlanLimit($this->tenant->getKey(), 'storage.max_bytes', 1000);
    app(EntitlementResolver::class)->forget($this->tenant);

    $file = UploadedFile::fake()->create('doc.pdf', 50, 'application/pdf'); // ~51KB

    expect(fn () => $this->service->execute($this->brand, $this->owner, $file))
        ->toThrow(EntitlementExceeded::class);

    // Nothing was written -- a post-hoc check would already have cost the disk.
    expect(Media::query()->count())->toBe(0);
});

it('counts existing media toward the quota', function (): void {
    givePlanLimit($this->tenant->getKey(), 'storage.max_bytes', 120_000);
    app(EntitlementResolver::class)->forget($this->tenant);

    $this->service->execute(
        $this->brand, $this->owner, UploadedFile::fake()->create('a.pdf', 50, 'application/pdf')
    );
    app(EntitlementResolver::class)->forget($this->tenant);

    $this->service->execute(
        $this->brand, $this->owner, UploadedFile::fake()->create('b.pdf', 50, 'application/pdf')
    );
    app(EntitlementResolver::class)->forget($this->tenant);

    expect(fn () => $this->service->execute(
        $this->brand, $this->owner, UploadedFile::fake()->create('c.pdf', 50, 'application/pdf')
    ))->toThrow(EntitlementExceeded::class);
});

it('accepts a folder belonging to the brand', function (): void {
    $folder = MediaFolder::factory()->forCustomer($this->brand)->create();

    $media = $this->service->execute(
        $this->brand, $this->owner, UploadedFile::fake()->image('a.jpg'), $folder->getKey()
    );

    expect($media->folder_id)->toBe($folder->getKey());
});

it('ignores a folder belonging to another brand', function (): void {
    $otherBrand = Customer::factory()->create(['tenant_id' => $this->tenant->getKey()]);
    $foreignFolder = MediaFolder::factory()->forCustomer($otherBrand)->create();

    $media = $this->service->execute(
        $this->brand, $this->owner, UploadedFile::fake()->image('a.jpg'), $foreignFolder->getKey()
    );

    // Silently dropped rather than honoured: the upload still succeeds, but
    // it cannot land in another brand's folder.
    expect($media->folder_id)->toBeNull();
});

it('marks a non-image as immediately ready', function (): void {
    $media = $this->service->execute(
        $this->brand, $this->owner, UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf')
    );

    expect($media->status)->toBe(MediaStatus::Ready);
});

it('records the disk per row so storage can be migrated file by file', function (): void {
    $media = $this->service->execute(
        $this->brand, $this->owner, UploadedFile::fake()->image('a.jpg')
    );

    expect($media->disk)->toBe(config('media.disk'));
});
