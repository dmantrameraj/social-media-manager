<?php

declare(strict_types=1);

use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Publishing\Enums\PostStatus;
use App\Domain\Publishing\Models\Post;
use App\Domain\Tenancy\Services\ProvisionTenantService;
use Database\Seeders\DemoContentSeeder;

/*
 | The demo content seeder.
 |
 | Seeders are not usually worth testing. This one is, because it writes into a
 | database somebody is already using: the person running it is normally doing
 | so precisely because they have been clicking around an empty install, and a
 | seeder that overwrites their work is worse than one that does nothing.
 |
 | That is not hypothetical -- the first version bailed out entirely when it
 | found any post at all, which meant one hand-made post blocked the whole
 | thing. These tests pin the behaviour that replaced it.
 */

beforeEach(function (): void {
    seedPermissions();

    $this->owner = User::factory()->create([
        'email' => config('platform.demo.email', 'demo@example.test'),
    ]);

    $this->tenant = app(ProvisionTenantService::class)->execute($this->owner, 'Demo Agency');
    $this->owner = $this->owner->fresh();

    actingForTenant($this->tenant);

    givePlanLimit($this->tenant->getKey(), 'brands.max', 25);
});

it('fills an empty demo agency', function (): void {
    $this->seed(DemoContentSeeder::class);

    expect(Customer::query()->count())->toBe(3)
        ->and(Post::query()->count())->toBeGreaterThan(30)
        // Every workflow state, because a demo showing only drafts shows
        // nothing about how the product behaves.
        ->and(Post::query()->where('status', PostStatus::Published->value)->exists())->toBeTrue()
        ->and(Post::query()->where('status', PostStatus::Scheduled->value)->exists())->toBeTrue()
        ->and(Post::query()->where('status', PostStatus::Rejected->value)->exists())->toBeTrue();
});

it('leaves a brand somebody has already written for alone', function (): void {
    $mine = Customer::factory()->create([
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'Roast House Coffee',
    ]);

    $post = Post::factory()->forCustomer($mine)->create(['title' => 'hello world']);

    $this->seed(DemoContentSeeder::class);

    // Untouched: same post, and nothing added beside it.
    expect($mine->posts()->count())->toBe(1)
        ->and($post->refresh()->title)->toBe('hello world');
});

it('never touches a brand it did not create', function (): void {
    /*
     | The seeder works from a named list. A brand somebody invented is not on
     | it, so it is not read, not counted and not written to -- which is a
     | stronger guarantee than "skipped if it has posts".
     */
    $mine = Customer::factory()->create([
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'MERAJ TECH SERVICES',
    ]);

    $this->seed(DemoContentSeeder::class);

    expect($mine->posts()->count())->toBe(0)
        ->and($mine->refresh()->name)->toBe('MERAJ TECH SERVICES')
        // And the demo brands were still created alongside it.
        ->and(Customer::query()->where('name', 'Harbour Books')->exists())->toBeTrue();
});

it('can be run twice without doubling anything', function (): void {
    $this->seed(DemoContentSeeder::class);
    $first = Post::query()->count();

    $this->seed(DemoContentSeeder::class);

    expect(Post::query()->count())->toBe($first)
        ->and(Customer::query()->count())->toBe(3);
});

it('does nothing without a demo user', function (): void {
    $this->owner->forceFill(['email' => 'somebody.else@example.test'])->save();

    $this->seed(DemoContentSeeder::class);

    expect(Post::query()->count())->toBe(0);
});

it('gives the published posts metrics to report on', function (): void {
    $this->seed(DemoContentSeeder::class);

    // Analytics with no rows is the same empty screen the seeder exists to
    // fill, so published history without metrics would be half a job.
    expect(DB::table('post_metrics')->count())->toBeGreaterThan(0);
});

it('leaves one reply unsent so that path can be seen', function (): void {
    $this->seed(DemoContentSeeder::class);

    expect(DB::table('inbox_messages')->where('delivery_status', 'failed')->exists())
        ->toBeTrue();
});
