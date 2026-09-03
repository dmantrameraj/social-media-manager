<?php

declare(strict_types=1);

use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Media\Models\Media;
use App\Domain\Media\Services\SignedMediaUrl;
use App\Domain\Tenancy\Services\ProvisionTenantService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Storage;

/*
 | Generating variants is only half of it. Until something asks for them the
 | library still streams full-size originals into 320px tiles, which is the
 | same "built and unreachable" shape as the job that never existed.
 */

beforeEach(function (): void {
    seedPermissions();
    Storage::fake('local');

    $this->owner = User::factory()->create();
    $this->tenant = app(ProvisionTenantService::class)->execute($this->owner, 'Bright Digital');
    $this->owner = $this->owner->fresh();

    actingForTenant($this->tenant);

    $this->brand = Customer::factory()->create(['tenant_id' => $this->tenant->getKey()]);

    $this->media = Media::factory()->forCustomer($this->brand)->create([
        'mime_type' => 'image/jpeg',
        'path' => 'media/original.jpg',
        'variants' => ['thumb' => 'media/variants/original-thumb.webp'],
        'thumbnail_path' => 'media/variants/original-thumb.webp',
    ]);

    Storage::disk('local')->put('media/original.jpg', str_repeat('O', 4096));
    Storage::disk('local')->put('media/variants/original-thumb.webp', 'tiny');
});

function asMediaStaff(User $user)
{
    return test()->actingAs($user, 'web')->withSession([
        config('tenancy.resolution.session_key', 'tenant_id') => test()->tenant->getKey(),
    ]);
}

it('serves the variant when one is asked for', function (): void {
    $response = asMediaStaff($this->owner)
        ->get(app(SignedMediaUrl::class)->forAgency($this->media, variant: 'thumb'))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/webp');

    expect($response->streamedContent())->toBe('tiny');
});

it('serves the original when no variant is asked for', function (): void {
    $response = asMediaStaff($this->owner)
        ->get(app(SignedMediaUrl::class)->forAgency($this->media))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg');

    expect(strlen($response->streamedContent()))->toBe(4096);
});

it('falls back to the original for a variant that was never generated', function (): void {
    /*
     | A missing thumbnail must show the picture, not a broken tile. Images
     | uploaded before the job existed have no variants at all, and the grid
     | still has to render them.
     */
    asMediaStaff($this->owner)
        ->get(app(SignedMediaUrl::class)->forAgency($this->media, variant: 'preview'))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg');
});

it('refuses a variant name that was not signed', function (): void {
    /*
     | The signature covers the query string, so the name cannot be swapped
     | after the fact. This is what lets the controller treat it as data at all.
     */
    $url = app(SignedMediaUrl::class)->forAgency($this->media, variant: 'thumb');

    asMediaStaff($this->owner)
        ->get(str_replace('variant=thumb', 'variant=preview', $url))
        ->assertForbidden();
});

it('treats a traversal attempt as a miss, not a path', function (): void {
    /*
     | The decisive property: the name is a KEY into the row's stored variants,
     | never joined onto a path. A name that is not a key simply misses, so
     | there is no filesystem to escape from.
     */
    $url = app(SignedMediaUrl::class)->forAgency($this->media, variant: '../../../../etc/passwd');

    $response = asMediaStaff($this->owner)->get($url)->assertOk();

    expect(strlen($response->streamedContent()))->toBe(4096);
});

it('still refuses another tenant is media whatever variant is asked for', function (): void {
    // Authorisation is unchanged by the variant: it is the same row either way.
    $otherOwner = User::factory()->create();
    $otherTenant = app(ProvisionTenantService::class)->execute($otherOwner, 'Rival Agency');

    app(TenantContext::class)->set($otherTenant);
    $foreignBrand = Customer::factory()->create(['tenant_id' => $otherTenant->getKey()]);
    $foreign = Media::factory()->forCustomer($foreignBrand)->create([
        'variants' => ['thumb' => 'media/variants/foreign-thumb.webp'],
    ]);
    Storage::disk('local')->put('media/variants/foreign-thumb.webp', 'secret');

    $url = app(SignedMediaUrl::class)->forAgency($foreign, variant: 'thumb');

    actingForTenant($this->tenant);

    asMediaStaff($this->owner)->get($url)->assertNotFound();
});
