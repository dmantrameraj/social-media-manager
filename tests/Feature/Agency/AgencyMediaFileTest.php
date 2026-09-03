<?php

declare(strict_types=1);

use App\Domain\Customers\Enums\PortalRole;
use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\CustomerPortalUser;
use App\Domain\Identity\Models\User;
use App\Domain\Media\Models\Media;
use App\Domain\Media\Services\SignedMediaUrl;
use App\Domain\Publishing\Models\Post;
use App\Domain\Tenancy\Services\ProvisionTenantService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
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

function asStaff(User $user)
{
    return test()->actingAs($user, 'web')->withSession([
        config('tenancy.resolution.session_key', 'tenant_id') => test()->tenant->getKey(),
    ]);
}

function storedMedia(Customer $brand, string $mime = 'image/jpeg'): Media
{
    $media = Media::factory()->forCustomer($brand)->create([
        'mime_type' => $mime,
        'alt_text' => 'A flat white on a wooden counter.',
    ]);

    Storage::disk($media->disk)->put($media->path, 'file-bytes');

    return $media;
}

// ------------------------------------------------------------------ serving

it('serves a file through a signed url', function (): void {
    $media = storedMedia($this->brand);

    asStaff($this->owner)
        ->get(app(SignedMediaUrl::class)->forAgency($media))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('refuses an unsigned request, so ids cannot be walked', function (): void {
    $media = storedMedia($this->brand);

    asStaff($this->owner)
        ->get(route('agency.media.file', $media))
        ->assertForbidden();
});

it('refuses a signed url with no agency session', function (): void {
    // A signed URL stays shareable until it expires, so the signature alone
    // must never be enough.
    $media = storedMedia($this->brand);

    $this->get(app(SignedMediaUrl::class)->forAgency($media))
        ->assertRedirect(route('login'));
});

it('expires', function (): void {
    $media = storedMedia($this->brand);
    $url = app(SignedMediaUrl::class)->forAgency($media, 60);

    asStaff($this->owner)->get($url)->assertOk();

    $this->travel(2)->minutes();

    asStaff($this->owner)->get($url)->assertForbidden();
});

it('404s when the file is gone rather than erroring', function (): void {
    // Retention can purge a file while the row still references it.
    $media = storedMedia($this->brand);
    Storage::disk($media->disk)->delete($media->path);

    asStaff($this->owner)
        ->get(app(SignedMediaUrl::class)->forAgency($media))
        ->assertNotFound();
});

// ---------------------------------------------------------------- isolation

it('refuses another tenant is media', function (): void {
    $otherOwner = User::factory()->create();
    $otherTenant = app(ProvisionTenantService::class)->execute($otherOwner, 'Rival Agency');

    app(TenantContext::class)->set($otherTenant);
    $foreignBrand = Customer::factory()->create(['tenant_id' => $otherTenant->getKey()]);
    $foreignMedia = storedMedia($foreignBrand);
    actingForTenant($this->tenant);

    asStaff($this->owner)
        ->get(app(SignedMediaUrl::class)->forAgency($foreignMedia))
        ->assertNotFound();
});

it('does not let a portal user use the agency route', function (): void {
    /*
     | The two routes exist separately because the surfaces authorise
     | differently. A client reaching the agency route would bypass the
     | workflow-stage check entirely -- the agency route asks only about tenant,
     | brand assignment and permission, not whether the post was ever sent.
     */
    $media = storedMedia($this->brand);

    $client = CustomerPortalUser::factory()
        ->forTenant($this->tenant)->create();
    $this->brand->portalUsers()->attach($client->getKey(), [
        'role' => PortalRole::Approver->value,
    ]);

    $this->actingAs($client->fresh(), 'customer')
        ->get(app(SignedMediaUrl::class)->forAgency($media))
        ->assertRedirect(route('login'));
});

// ------------------------------------------------------------------- screens

it('shows a thumbnail in the media library', function (): void {
    $media = storedMedia($this->brand);

    $body = asStaff($this->owner)
        ->get(route('agency.media.index', ['brand' => $this->brand->getKey()]))
        ->assertOk()
        ->getContent();

    expect(str_contains($body, 'app/media/'.$media->getRouteKey().'/file'))->toBeTrue()
        // The description is used as alt text, not the filename.
        ->and(str_contains($body, 'A flat white on a wooden counter.'))->toBeTrue()
        // Never a raw disk path.
        ->and(str_contains($body, $media->path))->toBeFalse();
});

it('shows attachments on the post, so staff are not approving a filename', function (): void {
    $media = storedMedia($this->brand);
    $post = Post::factory()->forCustomer($this->brand)->create();

    DB::table('post_media')->insert([
        'tenant_id' => $post->tenant_id,
        'post_id' => $post->getKey(),
        'media_id' => $media->getKey(),
        'sort_order' => 0,
        'role' => 'primary',
    ]);

    asStaff($this->owner)
        ->get(route('agency.posts.show', $post))
        ->assertOk()
        ->assertSee('app/media/'.$media->getRouteKey().'/file', false);
});

it('does not try to draw a thumbnail for a video', function (): void {
    // Drawing one would stream the whole file to fill a 64px tile.
    $video = storedMedia($this->brand, 'video/mp4');
    $video->forceFill(['extension' => 'mp4'])->save();

    $body = asStaff($this->owner)
        ->get(route('agency.media.index', ['brand' => $this->brand->getKey()]))
        ->assertOk()
        ->getContent();

    expect(str_contains($body, 'app/media/'.$video->getRouteKey().'/file'))->toBeFalse()
        ->and(str_contains($body, 'MP4'))->toBeTrue();
});
