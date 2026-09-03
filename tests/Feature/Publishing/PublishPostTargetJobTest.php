<?php

declare(strict_types=1);

use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Publishing\Enums\PostStatus;
use App\Domain\Publishing\Enums\TargetStatus;
use App\Domain\Publishing\Jobs\PublishPostTarget;
use App\Domain\Publishing\Models\Post;
use App\Domain\Publishing\Models\PostTarget;
use App\Domain\Publishing\Services\ClaimPostTargetService;
use App\Domain\Social\Enums\ProviderErrorClass;
use App\Domain\Social\Models\SocialAccount;
use App\Domain\Social\ProviderRegistry;
use App\Domain\Social\Providers\Fake\FakeProvider;
use App\Domain\Tenancy\Services\ProvisionTenantService;

/*
 | The job that finally connects a scheduled post to the engine that publishes
 | it -- and that writes the post's own status back, which
 | Post::deriveStatusFromTargets() computed for tests and nothing persisted.
 */

beforeEach(function (): void {
    seedPermissions();
    FakeProvider::reset();
    app(ProviderRegistry::class)->register('fake', FakeProvider::class);

    $owner = User::factory()->create();
    $this->tenant = app(ProvisionTenantService::class)->execute($owner, 'Bright Digital');
    actingForTenant($this->tenant);

    $this->brand = Customer::factory()->create(['tenant_id' => $this->tenant->getKey()]);
    $this->account = SocialAccount::factory()->forCustomer($this->brand)->create();
    $this->post = Post::factory()->forCustomer($this->brand)
        ->status(PostStatus::Processing)->create();
});

/** A target already claimed, as the dispatcher leaves it. */
function claimedTarget(): PostTarget
{
    $target = PostTarget::factory()
        ->targeting(test()->post, test()->account)
        ->create(['status' => TargetStatus::Scheduled, 'scheduled_at' => now()->subMinute()]);

    app(ClaimPostTargetService::class)->claim($target);

    return $target->fresh();
}

function runJob(PostTarget $target): void
{
    app()->call([
        new PublishPostTarget(test()->tenant->getKey(), $target->getKey()),
        'handle',
    ]);
}

// ------------------------------------------------------------------ the point

it('publishes a claimed target', function (): void {
    $target = claimedTarget();
    FakeProvider::willSucceedWith('external-1');

    runJob($target);

    expect($target->fresh()->status)->toBe(TargetStatus::Published)
        ->and($target->fresh()->external_post_id)->toBe('external-1');
});

it('writes the post status back, which nothing did before', function (): void {
    /*
     | deriveStatusFromTargets() has existed since Phase 3 and was called only
     | by tests. Without this the post stayed `processing` on the dashboard,
     | the calendar and the client's portal for ever.
     */
    $target = claimedTarget();
    FakeProvider::willSucceedWith('external-1');

    runJob($target);

    expect($this->post->fresh()->status)->toBe(PostStatus::Published);
});

it('lands on partially published when one target of two fails', function (): void {
    /*
     | Both targets exist before either publishes, which is how a real post
     | works -- the composer creates every target, then the post is scheduled.
     |
     | That ordering is what makes the outcome reachable at all. settlePost()
     | returns early while any target is still in flight, so the post only
     | moves once they have all settled; the FIRST worker to finish does not
     | complete the post to `published` behind the second one's back. It could
     | not recover if it did -- `published` is terminal in PostStatusMachine,
     | so a later failure would have nowhere legal to move the post to.
     */
    $secondAccount = SocialAccount::factory()->forCustomer($this->brand)->create();

    $first = PostTarget::factory()->targeting($this->post, $this->account)
        ->create(['status' => TargetStatus::Scheduled, 'scheduled_at' => now()->subMinute()]);

    $second = PostTarget::factory()->targeting($this->post, $secondAccount)
        ->create(['status' => TargetStatus::Scheduled, 'scheduled_at' => now()->subMinute()]);

    app(ClaimPostTargetService::class)->claim($first);
    app(ClaimPostTargetService::class)->claim($second);

    FakeProvider::willSucceedWith('external-1');
    runJob($first->fresh());

    // Still in flight, so the post has not settled yet.
    expect($this->post->fresh()->status)->toBe(PostStatus::Processing);

    FakeProvider::willFailWith(ProviderErrorClass::PlatformRejection, 'Rejected');
    runJob($second->fresh());

    expect($this->post->fresh()->status)->toBe(PostStatus::PartiallyPublished);
});

// ------------------------------------------------------------- double-posting

it('does nothing to a target that is not claimed', function (): void {
    /*
     | The guard against double-posting. A redelivered message, or one queued
     | before the stale sweep released the row, must not publish a second time.
     */
    $target = PostTarget::factory()
        ->targeting($this->post, $this->account)
        ->create(['status' => TargetStatus::Scheduled, 'scheduled_at' => now()->subMinute()]);

    FakeProvider::willSucceedWith('should-not-happen');

    runJob($target);

    expect($target->fresh()->status)->toBe(TargetStatus::Scheduled)
        ->and($target->fresh()->external_post_id)->toBeNull();
});

it('does nothing to a target already published', function (): void {
    $target = claimedTarget();
    FakeProvider::willSucceedWith('external-1');
    runJob($target);

    // A second delivery of the same job.
    FakeProvider::willSucceedWith('external-2');
    runJob($target->fresh());

    expect($target->fresh()->external_post_id)->toBe('external-1');
});

// -------------------------------------------------------------- missing rows

it('returns quietly when the target is gone', function (): void {
    // Cancelled between dispatch and pickup is ordinary, not an error.
    $target = claimedTarget();
    $id = $target->getKey();
    $target->forceDelete();

    app()->call([new PublishPostTarget($this->tenant->getKey(), $id), 'handle']);

    // Asserted rather than left to throwsNoExceptions() alone, which registers
    // no assertion and leaves the test technically risky: the point is that the
    // job did nothing, not merely that it did not blow up.
    expect(PostTarget::query()->find($id))->toBeNull()
        ->and($this->post->fresh()->status)->toBe(PostStatus::Processing);
});

it('returns quietly when the tenant is gone', function (): void {
    $target = claimedTarget();

    app()->call([new PublishPostTarget(999_999, $target->getKey()), 'handle']);

    // No throwsNoExceptions(): the assertion below only runs if the call
    // returned, so it already proves the job did not throw -- and pairing the
    // two is what Pest marks risky.
    expect($target->fresh()->status)->toBe(TargetStatus::Processing);
});

// ------------------------------------------------------------------- routing

it('runs on the publishing queue', function (): void {
    // Named separately so a backlog of posts cannot starve notifications.
    expect((new PublishPostTarget(1, 1))->queue)->toBe('publishing');
});
