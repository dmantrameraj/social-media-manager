<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Publishing\Enums\PostStatus;
use App\Domain\Publishing\Enums\TargetStatus;
use App\Domain\Publishing\Models\Post;
use App\Domain\Publishing\Models\PostTarget;
use App\Domain\Social\Models\SocialAccount;
use App\Domain\Tenancy\Services\ProvisionTenantService;
use Illuminate\Support\Carbon;

/*
 | Moving a post in time.
 |
 | The roadmap asked for "calendar drag-and-drop re-validates server-side".
 | That sentence is really two requirements, and the second is the one worth
 | testing: the browser may send anything, so every rule is applied again here.
 */

beforeEach(function (): void {
    seedPermissions();

    // A fixed clock. Lead times, DST dates and "is this in the future" are all
    // relative to now, and a test that drifts with the calendar is a test that
    // fails on a Sunday in March for no reason anyone can reproduce.
    $this->travelTo(Carbon::parse('2026-03-01 12:00:00', 'UTC'));

    $this->owner = User::factory()->create();
    $this->tenant = app(ProvisionTenantService::class)->execute($this->owner, 'Bright Digital');
    $this->owner = $this->owner->fresh();

    actingForTenant($this->tenant);

    $this->brand = Customer::factory()->create([
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'Roast House',
        'timezone' => 'Asia/Kolkata',
    ]);

    $this->account = SocialAccount::factory()->forCustomer($this->brand)->create();
});

/** A scheduled post with one target, both at the same time. */
function scheduledPost(string $at = '2026-03-10 09:00:00', array $targets = [null]): Post
{
    $utc = Carbon::parse($at, test()->brand->timezone)->utc();

    $post = Post::factory()
        ->forCustomer(test()->brand)
        ->scheduledFor($utc)
        ->create();

    foreach ($targets as $index => $status) {
        // A separate account per target: post_targets is unique on
        // (post_id, social_account_id), because publishing the same post twice
        // to the same account is a duplicate, not a second target.
        $account = $index === 0
            ? test()->account
            : SocialAccount::factory()->forCustomer(test()->brand)->create();

        PostTarget::factory()
            ->targeting($post, $account)
            ->status($status ?? TargetStatus::Scheduled)
            ->create(['scheduled_at' => $utc]);
    }

    return $post;
}

it('moves the post and its targets together', function (): void {
    $post = scheduledPost();

    asAgencyUser($this->owner)
        ->post(route('agency.posts.reschedule', $post), ['date' => '2026-03-20'])
        ->assertRedirect();

    $post->refresh();

    /*
     | The target is the half that matters. The dispatcher reads
     | post_targets.scheduled_at and never the post's, so a reschedule that
     | moved only the post would change what the calendar shows and nothing
     | about when the post goes out -- a failure discovered by a client.
     */
    expect($post->scheduled_at->setTimezone($this->brand->timezone)->toDateString())
        ->toBe('2026-03-20')
        ->and($post->targets()->pluck('scheduled_at')->unique()->all())
        ->toHaveCount(1)
        ->and($post->targets()->first()->scheduled_at->equalTo($post->scheduled_at))
        ->toBeTrue();
});

it('keeps the time of day when a post is dropped on another day', function (): void {
    // The gesture says WHICH DAY. Dragging a 09:00 post to next Friday must
    // not silently make it a midnight post.
    $post = scheduledPost('2026-03-10 14:30:00');

    asAgencyUser($this->owner)
        ->post(route('agency.posts.reschedule', $post), ['date' => '2026-03-20']);

    $local = $post->refresh()->scheduled_at->setTimezone($this->brand->timezone);

    expect($local->format('Y-m-d H:i'))->toBe('2026-03-20 14:30');
});

it('refuses a time inside the lead window', function (): void {
    /*
     | The browser is not asked. A drag onto today with a past time, or a
     | crafted request, meets the same floor the composer enforces -- otherwise
     | drag-and-drop is a way to schedule into the sweeper's current pass.
     */
    $post = scheduledPost();

    asAgencyUser($this->owner)
        ->post(route('agency.posts.reschedule', $post), [
            'scheduled_at' => now()->addSeconds(5)->format('Y-m-d H:i:s'),
        ])
        ->assertSessionHas('error');

    expect($post->refresh()->scheduled_at->setTimezone($this->brand->timezone)->toDateString())
        ->toBe('2026-03-10');
});

it('refuses to move a post that is being published right now', function (): void {
    /*
     | A post moves to Processing only once the FIRST target is claimed, so
     | there is a window where the post still reads Scheduled while a worker
     | holds a target. Checking the post's status alone would let a post be
     | moved out from under the worker publishing it.
     */
    $post = scheduledPost(targets: [TargetStatus::Processing]);

    asAgencyUser($this->owner)
        ->post(route('agency.posts.reschedule', $post), ['date' => '2026-03-20'])
        ->assertSessionHas('error');

    expect($post->refresh()->scheduled_at->setTimezone($this->brand->timezone)->toDateString())
        ->toBe('2026-03-10');
});

it('refuses to move a published post', function (): void {
    $post = scheduledPost();
    $post->forceFill(['status' => PostStatus::Published->value])->save();

    asAgencyUser($this->owner)
        ->postJson(route('agency.posts.reschedule', $post), ['date' => '2026-03-20'])
        ->assertStatus(422);
});

it('leaves a target that has already gone out where it is', function (): void {
    /*
     | A published target's time is history: it records when content actually
     | appeared on a network. Rewriting it would make our publication log
     | disagree with the network it describes.
     */
    $post = scheduledPost(targets: [TargetStatus::Scheduled, TargetStatus::Published]);
    $post->forceFill(['status' => PostStatus::Failed->value])->save();

    $published = $post->targets()->where('status', TargetStatus::Published->value)->sole();
    $was = $published->scheduled_at->copy();

    asAgencyUser($this->owner)
        ->post(route('agency.posts.reschedule', $post), ['date' => '2026-03-20'])
        ->assertRedirect();

    expect($published->refresh()->scheduled_at->equalTo($was))->toBeTrue()
        ->and($post->targets()->where('status', TargetStatus::Scheduled->value)
            ->sole()->scheduled_at->setTimezone($this->brand->timezone)->toDateString())
        ->toBe('2026-03-20');
});

it('clears a retry backoff computed against the old time', function (): void {
    $post = scheduledPost(targets: [TargetStatus::Failed]);
    $post->targets()->update(['next_attempt_at' => now()->addDays(2)]);

    asAgencyUser($this->owner)
        ->post(route('agency.posts.reschedule', $post), ['date' => '2026-03-20']);

    // A delay belonging to a schedule that no longer exists would hold the
    // moved target back past its new time.
    expect($post->targets()->first()->next_attempt_at)->toBeNull();
});

it('needs the scheduling permission', function (): void {
    $post = scheduledPost();

    // A Designer can see the calendar and not move anything on it.
    asAgencyUser(memberWithRole($this->tenant, 'Designer'))
        ->post(route('agency.posts.reschedule', $post), ['date' => '2026-03-20'])
        ->assertForbidden();
});

it('cannot move another agency post', function (): void {
    [$rival] = provisionTenant('Rival Agency');
    actingForTenant($rival);

    $rivalBrand = Customer::factory()->create([
        'tenant_id' => $rival->getKey(),
        'timezone' => 'Asia/Kolkata',
    ]);
    $rivalPost = Post::factory()->forCustomer($rivalBrand)
        ->scheduledFor(now()->addDays(9))->create();

    actingForTenant($this->tenant);

    // 404, not 403: whether a post exists is itself not theirs to learn.
    asAgencyUser($this->owner)
        ->post(route('agency.posts.reschedule', $rivalPost), ['date' => '2026-03-20'])
        ->assertNotFound();
});

it('records the move', function (): void {
    $post = scheduledPost();

    asAgencyUser($this->owner)
        ->post(route('agency.posts.reschedule', $post), ['date' => '2026-03-20']);

    $entry = AuditLog::query()->where('action', 'post.rescheduled')->sole();

    expect($entry->auditable_id)->toEqual($post->getKey())
        ->and($entry->new_values['scheduled_at'])->not->toBeNull()
        ->and($entry->old_values['scheduled_at'])->not->toBeNull();
});
