<?php

declare(strict_types=1);

use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Media\Models\Media;
use App\Domain\Media\Services\ProviderMediaUrl;
use App\Domain\Social\DTO\MediaItem;
use App\Domain\Tenancy\Services\ProvisionTenantService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/*
 | The one image URL a social network can fetch.
 |
 | Instagram will not take image bytes: it takes an image_url and pulls the
 | file from its own servers, unauthenticated. Every other media route here
 | requires a signed-in agency or portal user, so this endpoint exists — and
 | being the second unauthenticated view of tenant data in the application
 | (after the report share link), most of what matters is what it REFUSES.
 */

beforeEach(function (): void {
    seedPermissions();
    Storage::fake('local');

    config()->set('app.url', 'https://agency.example.com');

    $owner = User::factory()->create();
    $this->tenant = app(ProvisionTenantService::class)->execute($owner, 'Bright Digital');
    actingForTenant($this->tenant);

    $this->brand = Customer::factory()->create(['tenant_id' => $this->tenant->getKey()]);

    $this->media = Media::factory()->forCustomer($this->brand)->create([
        'disk' => 'local',
        'path' => 'media/beans.jpg',
        'mime_type' => 'image/jpeg',
    ]);

    Storage::disk('local')->put('media/beans.jpg', 'not-really-a-jpeg');

    $this->item = new MediaItem(
        id: $this->media->getKey(),
        path: $this->media->path,
        disk: 'local',
        mimeType: 'image/jpeg',
        sizeBytes: 17,
    );
});

it('serves the file to an anonymous caller holding a signed URL', function (): void {
    // The caller is a machine in someone else's data centre. There is no
    // session to authorise, so the signature is the authorisation.
    $url = app(ProviderMediaUrl::class)->for($this->item);

    $this->get($url)
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg')
        // Fetched once by a machine, and the URL dies minutes later; anything
        // cached along the way outlives the permission that produced it.
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

it('refuses a request with no signature', function (): void {
    $this->get(route('media.provider', ['media' => $this->media->ulid]))
        ->assertForbidden();
});

it('refuses a signature that has expired', function (): void {
    $url = URL::temporarySignedRoute('media.provider', now()->subMinute(), [
        'media' => $this->media->ulid,
    ]);

    // The signature covers the expiry, so neither can be edited.
    $this->get($url)->assertForbidden();
});

it('refuses a URL edited to point at another file', function (): void {
    $other = Media::factory()->forCustomer($this->brand)->create(['ulid' => 'other-ulid-here']);

    $url = app(ProviderMediaUrl::class)->for($this->item);
    $tampered = str_replace($this->media->ulid, $other->ulid, $url);

    $this->get($tampered)->assertForbidden();
});

it('is a 404 for media that is not ready', function (): void {
    $processing = Media::factory()->forCustomer($this->brand)->processing()->create([
        'disk' => 'local',
        'path' => 'media/pending.jpg',
    ]);

    $url = URL::temporarySignedRoute('media.provider', now()->addMinutes(5), [
        'media' => $processing->ulid,
    ]);

    // Publishing a half-written file would put a broken image on a client's
    // feed, so unusable media is refused even with a valid signature.
    $this->get($url)->assertNotFound();
});

it('is a 404 when the file has gone', function (): void {
    Storage::disk('local')->delete('media/beans.jpg');

    $this->get(app(ProviderMediaUrl::class)->for($this->item))->assertNotFound();
});

it('mints nothing for media that cannot be published', function (): void {
    $processing = Media::factory()->forCustomer($this->brand)->processing()->create();

    $item = new MediaItem(
        id: $processing->getKey(),
        path: $processing->path,
        disk: $processing->disk,
        mimeType: 'image/jpeg',
        sizeBytes: 1,
    );

    // Null, so the adapter refuses to publish rather than handing Meta a URL
    // that will 404 at their end minutes later.
    expect(app(ProviderMediaUrl::class)->for($item))->toBeNull();
});

it('will not offer a URL a provider cannot reach', function (): void {
    /*
     | A signed URL on localhost is valid and useless: Meta fetches from its
     | own network. Saying so before publishing turns a baffling provider error
     | into a sentence somebody can act on.
     */
    foreach (['http://localhost:8000', 'http://127.0.0.1', 'https://smm.test'] as $url) {
        config()->set('app.url', $url);

        expect(app(ProviderMediaUrl::class)->isReachable())->toBeFalse();
    }

    config()->set('app.url', 'https://agency.example.com');

    expect(app(ProviderMediaUrl::class)->isReachable())->toBeTrue();
});
