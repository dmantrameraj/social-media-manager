<?php

declare(strict_types=1);

use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Media\Models\Media;
use App\Domain\Media\Services\StoreMediaService;
use App\Domain\Social\DTO\MediaItem;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Services\ProvisionTenantService;
use App\Support\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    seedPermissions();
    Storage::fake('local');

    $this->owner = User::factory()->create();
    $this->tenant = app(ProvisionTenantService::class)->execute($this->owner, 'Bright Digital');
    $this->owner = $this->owner->fresh();

    actingForTenant($this->tenant);

    $this->brand = Customer::factory()->create(['tenant_id' => $this->tenant->getKey()]);
});

/** Sign in with the workspace already selected. */
function asAgency(User $user)
{
    return test()->actingAs($user, 'web')->withSession([
        config('tenancy.resolution.session_key', 'tenant_id') => test()->tenant->getKey(),
    ]);
}

// ------------------------------------------------------------------ capture

it('captures a description at upload', function (): void {
    // At upload, while the person who chose the file is still looking at it.
    asAgency($this->owner)
        ->post(route('agency.media.store'), [
            'brand' => $this->brand->getKey(),
            'file' => UploadedFile::fake()->image('coffee.jpg'),
            'alt_text' => 'A flat white on a wooden counter, seen from above.',
        ])
        ->assertRedirect();

    $media = Media::query()->latest('id')->first();

    expect($media->alt_text)->toBe('A flat white on a wooden counter, seen from above.')
        ->and($media->needsAltText())->toBeFalse();
});

it('stores no description rather than an empty one', function (): void {
    /*
     | Null, not ''. A screen reader announces an EMPTY alt as though the image
     | were decorative and skips it entirely, so "no description" and "an empty
     | description" must not be the same state.
     */
    $media = app(StoreMediaService::class)->execute(
        $this->brand,
        $this->owner,
        UploadedFile::fake()->image('coffee.jpg'),
        altText: '    ',
    );

    expect($media->alt_text)->toBeNull()
        ->and($media->needsAltText())->toBeTrue();
});

it('does not require a description, because a forced one is worse than none', function (): void {
    // A required field produces a character typed to clear it, which a screen
    // reader then announces as though it were a description.
    asAgency($this->owner)
        ->post(route('agency.media.store'), [
            'brand' => $this->brand->getKey(),
            'file' => UploadedFile::fake()->image('coffee.jpg'),
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(Media::query()->count())->toBe(1);
});

it('caps a very long description instead of failing the upload', function (): void {
    $media = app(StoreMediaService::class)->execute(
        $this->brand,
        $this->owner,
        UploadedFile::fake()->image('coffee.jpg'),
        altText: str_repeat('a', 5000),
    );

    expect(mb_strlen((string) $media->alt_text))->toBeLessThanOrEqual(1000);
});

// --------------------------------------------------------------------- edit

it('lets someone add a description to a file that predates the feature', function (): void {
    $media = Media::factory()->forCustomer($this->brand)->create(['alt_text' => null]);

    expect($media->needsAltText())->toBeTrue();

    asAgency($this->owner)
        ->put(route('agency.media.update', $media), [
            'alt_text' => 'Two people laughing at a corner table.',
        ])
        ->assertRedirect();

    expect($media->fresh()->alt_text)->toBe('Two people laughing at a corner table.');
});

it('clears a description back to null rather than blank', function (): void {
    $media = Media::factory()->forCustomer($this->brand)->create(['alt_text' => 'Something']);

    asAgency($this->owner)
        ->put(route('agency.media.update', $media), ['alt_text' => '   '])
        ->assertRedirect();

    expect($media->fresh()->alt_text)->toBeNull();
});

it('refuses to let one tenant describe another tenant is media', function (): void {
    $otherOwner = User::factory()->create();
    $otherTenant = app(ProvisionTenantService::class)->execute($otherOwner, 'Rival Agency');

    app(TenantContext::class)->set($otherTenant);
    $foreignBrand = Customer::factory()->create(['tenant_id' => $otherTenant->getKey()]);
    $foreignMedia = Media::factory()->forCustomer($foreignBrand)->create();
    actingForTenant($this->tenant);

    // 404 rather than 403: the tenant scope removes the row during binding, so
    // a foreign record is indistinguishable from a missing one.
    asAgency($this->owner)
        ->put(route('agency.media.update', $foreignMedia), ['alt_text' => 'Not mine'])
        ->assertNotFound();

    expect($foreignMedia->fresh()->alt_text)->not->toBe('Not mine');
});

// ---------------------------------------------------------------- behaviour

it('never leaves an image element silent', function (): void {
    // describedAs() falls back to the filename so the element is never empty --
    // but needsAltText() still reports the gap, because a filename is not a
    // description and must not quietly count as one.
    $described = Media::factory()->forCustomer($this->brand)->create(['alt_text' => 'A dog.']);
    $bare = Media::factory()->forCustomer($this->brand)->create([
        'alt_text' => null,
        'original_name' => 'DSC_0041.jpg',
    ]);

    expect($described->describedAs())->toBe('A dog.')
        ->and($bare->describedAs())->toContain('DSC_0041.jpg')
        ->and($bare->needsAltText())->toBeTrue()
        ->and($described->needsAltText())->toBeFalse();
});

it('does not ask for a description of a PDF', function (): void {
    // A document's accessibility is a property of the document. Prompting for
    // one trains people to type something meaningless to clear the warning.
    $pdf = Media::factory()->forCustomer($this->brand)->create([
        'mime_type' => 'application/pdf',
        'extension' => 'pdf',
        'alt_text' => null,
    ]);

    expect($pdf->needsAltText())->toBeFalse();
});

it('carries the description to the provider payload', function (): void {
    // The point of capturing it: the PUBLISHED post is described, not just the
    // preview in this application.
    $item = new MediaItem(
        id: 1,
        path: 'media/x.jpg',
        disk: 'local',
        mimeType: 'image/jpeg',
        sizeBytes: 1000,
        altText: 'A flat white on a wooden counter.',
    );

    expect($item->hasAltText())->toBeTrue()
        ->and($item->altText)->toBe('A flat white on a wooden counter.');

    expect((new MediaItem(2, 'media/y.jpg', 'local', 'image/jpeg', 1000))->hasAltText())
        ->toBeFalse();
});

it('shows the client the description that will be published', function (): void {
    /*
     | Shown, not merely applied to the img element: the description goes out
     | with the post, so it is part of what the client is approving.
     */
    $media = Media::factory()->forCustomer($this->brand)->create([
        'alt_text' => 'A flat white on a wooden counter.',
    ]);

    expect($media->describedAs())->toBe('A flat white on a wooden counter.');
});
