<?php

declare(strict_types=1);

use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Media\Enums\MediaStatus;
use App\Domain\Media\Models\Media;
use App\Domain\Publishing\Models\Post;
use App\Domain\Tenancy\Services\ProvisionTenantService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    seedPermissions();

    $this->owner = User::factory()->create();
    $this->tenant = app(ProvisionTenantService::class)->execute($this->owner, 'Bright Digital');
    $this->owner = $this->owner->fresh();

    actingForTenant($this->tenant);

    $this->brand = Customer::factory()->create(['tenant_id' => $this->tenant->getKey()]);
    $this->otherBrand = Customer::factory()->create(['tenant_id' => $this->tenant->getKey()]);
});

function asComposer(User $user)
{
    return test()->actingAs($user, 'web')->withSession([
        config('tenancy.resolution.session_key', 'tenant_id') => test()->tenant->getKey(),
    ]);
}

/** @param  array<int, int>  $mediaIds */
function composePost(User $user, Customer $brand, array $mediaIds)
{
    return asComposer($user)->post(route('agency.posts.store'), [
        'customer_id' => $brand->getKey(),
        'body' => 'A post with attachments.',
        'media' => $mediaIds,
    ]);
}

// ------------------------------------------------------------------- attach

it('attaches media to a new post', function (): void {
    // Without this the portal's media previews are unreachable: nothing in the
    // UI could put a file on a post.
    $first = Media::factory()->forCustomer($this->brand)->create();
    $second = Media::factory()->forCustomer($this->brand)->create();

    composePost($this->owner, $this->brand, [$first->getKey(), $second->getKey()])
        ->assertRedirect();

    $post = Post::query()->latest('id')->first();

    expect($post->media)->toHaveCount(2)
        ->and($post->media->pluck('id')->all())->toBe([$first->getKey(), $second->getKey()]);
});

it('keeps the order the author submitted, not the library order', function (): void {
    /*
     | A carousel is a sequence someone arranged deliberately. whereIn returns
     | rows in whatever order the database likes, so the submitted order has to
     | be re-imposed -- and it is what the portal and every provider read back.
     */
    $a = Media::factory()->forCustomer($this->brand)->create();
    $b = Media::factory()->forCustomer($this->brand)->create();
    $c = Media::factory()->forCustomer($this->brand)->create();

    composePost($this->owner, $this->brand, [$c->getKey(), $a->getKey(), $b->getKey()]);

    expect(Post::query()->latest('id')->first()->media->pluck('id')->all())
        ->toBe([$c->getKey(), $a->getKey(), $b->getKey()]);
});

it('refuses media belonging to another brand', function (): void {
    // Ids arrive from a form. `exists:media,id` would accept this happily,
    // because it only asks whether the row exists.
    $mine = Media::factory()->forCustomer($this->brand)->create();
    $theirs = Media::factory()->forCustomer($this->otherBrand)->create();

    composePost($this->owner, $this->brand, [$mine->getKey(), $theirs->getKey()]);

    $post = Post::query()->latest('id')->first();

    expect($post->media->pluck('id')->all())->toBe([$mine->getKey()])
        ->and($post->media->pluck('id')->all())->not->toContain($theirs->getKey());
});

it('refuses media belonging to another tenant', function (): void {
    $otherOwner = User::factory()->create();
    $otherTenant = app(ProvisionTenantService::class)->execute($otherOwner, 'Rival Agency');

    app(TenantContext::class)->set($otherTenant);
    $foreignBrand = Customer::factory()->create(['tenant_id' => $otherTenant->getKey()]);
    $foreignMedia = Media::factory()->forCustomer($foreignBrand)->create();
    actingForTenant($this->tenant);

    composePost($this->owner, $this->brand, [$foreignMedia->getKey()]);

    expect(Post::query()->latest('id')->first()->media)->toHaveCount(0);
});

it('refuses a file that is not finished uploading', function (): void {
    // Attaching one that is still processing would fail at publish time, which
    // is the worst possible moment to find out.
    $processing = Media::factory()->forCustomer($this->brand)->create([
        'status' => MediaStatus::Processing,
    ]);

    composePost($this->owner, $this->brand, [$processing->getKey()]);

    expect(Post::query()->latest('id')->first()->media)->toHaveCount(0);
});

it('caps how many files one post may carry', function (): void {
    $ids = Media::factory()->forCustomer($this->brand)->count(11)->create()->pluck('id')->all();

    composePost($this->owner, $this->brand, $ids)->assertSessionHasErrors('media');

    expect(Post::query()->count())->toBe(0);
});

it('ignores a duplicate id rather than attaching twice', function (): void {
    $media = Media::factory()->forCustomer($this->brand)->create();

    composePost($this->owner, $this->brand, [$media->getKey(), $media->getKey()]);

    expect(DB::table('post_media')->count())->toBe(1);
});

// -------------------------------------------------------------- content type

it('labels the post by what is actually attached', function (): void {
    /*
     | Providers branch on content_type -- a video post and an image post are
     | different API calls on every network -- so hardcoding 'text' would
     | mislabel every post carrying media.
     */
    $image = Media::factory()->forCustomer($this->brand)->create();

    composePost($this->owner, $this->brand, [$image->getKey()]);
    expect(Post::query()->latest('id')->first()->content_type)->toBe('image');

    $video = Media::factory()->forCustomer($this->brand)->create([
        'mime_type' => 'video/mp4',
        'extension' => 'mp4',
    ]);

    composePost($this->owner, $this->brand, [$image->getKey(), $video->getKey()]);
    expect(Post::query()->latest('id')->first()->content_type)->toBe('video');

    composePost($this->owner, $this->brand, []);
    expect(Post::query()->latest('id')->first()->content_type)->toBe('text');
});

// --------------------------------------------------------------------- UI

it('offers only usable files from brands the author can see', function (): void {
    $ready = Media::factory()->forCustomer($this->brand)->create(['original_name' => 'usable.jpg']);
    $processing = Media::factory()->forCustomer($this->brand)->create([
        'original_name' => 'halfway.jpg',
        'status' => MediaStatus::Processing,
    ]);

    $body = asComposer($this->owner)->get(route('agency.posts.create'))->assertOk()->getContent();

    expect(str_contains($body, $ready->original_name))->toBeTrue()
        ->and(str_contains($body, $processing->original_name))->toBeFalse(
            'A file that is still processing was offered for attachment.',
        );
});

it('flags a file with no description at the moment of attaching it', function (): void {
    // Surfaced where it can still be fixed before the post goes out.
    Media::factory()->forCustomer($this->brand)->create(['alt_text' => null]);

    asComposer($this->owner)
        ->get(route('agency.posts.create'))
        ->assertOk()
        ->assertSee('No description');
});

it('flags a missing description on the post itself too', function (): void {
    $media = Media::factory()->forCustomer($this->brand)->create(['alt_text' => null]);

    composePost($this->owner, $this->brand, [$media->getKey()]);
    $post = Post::query()->latest('id')->first();

    asComposer($this->owner)
        ->get(route('agency.posts.show', $post))
        ->assertOk()
        ->assertSee('No description');
});
