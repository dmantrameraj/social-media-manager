<?php

declare(strict_types=1);

use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Publishing\Enums\PostStatus;
use App\Domain\Publishing\Enums\TargetStatus;
use App\Domain\Publishing\Jobs\PublishPostTarget;
use App\Domain\Publishing\Models\Post;
use App\Domain\Publishing\Models\PostTarget;
use App\Domain\Social\Models\SocialAccount;
use App\Domain\Social\ProviderRegistry;
use App\Domain\Social\Providers\Fake\FakeProvider;
use App\Domain\Tenancy\Services\ProvisionTenantService;
use Illuminate\Support\Facades\Queue;

/*
 | Nothing drove the publishing engine. PostTarget::due() and PostTarget::
 | stale() were written and tested, the whole claim/retry/backoff engine was
 | complete -- and no command ever ran the query, so a post could be drafted,
 | approved by the client and scheduled, then sit there for ever.
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
        ->status(PostStatus::Scheduled)->create();
});

/** A target scheduled for a given moment. */
function dueTarget(array $overrides = []): PostTarget
{
    return PostTarget::factory()
        ->targeting(test()->post, test()->account)
        ->create(array_merge([
            'status' => TargetStatus::Scheduled,
            'scheduled_at' => now()->subMinute(),
        ], $overrides));
}

// ----------------------------------------------------------------- selection

it('queues a target whose time has come', function (): void {
    Queue::fake();

    $target = dueTarget();

    $this->artisan('publishing:dispatch-due')->assertSuccessful();

    Queue::assertPushed(
        PublishPostTarget::class,
        fn (PublishPostTarget $job): bool => $job->targetId === $target->getKey()
            && $job->tenantId === $this->tenant->getKey(),
    );
});

it('leaves a target scheduled for later alone', function (): void {
    Queue::fake();

    dueTarget(['scheduled_at' => now()->addHour()]);

    $this->artisan('publishing:dispatch-due')->assertSuccessful();

    Queue::assertNothingPushed();
});

it('respects a backoff the engine set', function (): void {
    // next_attempt_at is how the engine rations retries. Dispatching before it
    // would spend an attempt the engine deliberately deferred.
    Queue::fake();

    dueTarget(['next_attempt_at' => now()->addHour()]);

    $this->artisan('publishing:dispatch-due')->assertSuccessful();

    Queue::assertNothingPushed();
});

it('claims the row before queueing it', function (): void {
    /*
     | The claim is the atomic step that makes double-publishing impossible,
     | so it happens next to the decision to publish rather than inside the
     | job -- docs/06-PUBLISHING-ENGINE.md §6.
     */
    Queue::fake();

    $target = dueTarget();

    $this->artisan('publishing:dispatch-due');

    expect($target->fresh()->status)->toBe(TargetStatus::Processing)
        ->and($target->fresh()->locked_at)->not->toBeNull();
});

it('does not queue the same target twice across two ticks', function (): void {
    // The second tick finds it already processing, so due() skips it.
    Queue::fake();

    dueTarget();

    $this->artisan('publishing:dispatch-due');
    $this->artisan('publishing:dispatch-due');

    Queue::assertPushed(PublishPostTarget::class, 1);
});

it('queues nothing on a dry run', function (): void {
    Queue::fake();

    $target = dueTarget();

    $this->artisan('publishing:dispatch-due --dry-run')->assertSuccessful();

    Queue::assertNothingPushed();

    // And leaves the row unclaimed, which is what makes it a dry run.
    expect($target->fresh()->status)->toBe(TargetStatus::Scheduled);
});

// ------------------------------------------------------------ stale recovery

it('releases a lock whose worker died', function (): void {
    /*
     | due() only looks at scheduled rows, so a target stuck in processing is
     | invisible to it: without this sweep the post is never published and
     | nothing reports why.
     */
    Queue::fake();

    $ttl = (int) config('publishing.lock_ttl', 900);

    $stale = dueTarget([
        'status' => TargetStatus::Processing,
        'locked_at' => now()->subSeconds($ttl + 60),
        'locked_by' => 'worker-that-died',
    ]);

    $this->artisan('publishing:dispatch-due')->assertSuccessful();

    // Released, then picked up by the same pass.
    expect($stale->fresh()->locked_by)->not->toBe('worker-that-died');
});

it('does not disturb a lock that is still fresh', function (): void {
    Queue::fake();

    $fresh = dueTarget([
        'status' => TargetStatus::Processing,
        'locked_at' => now(),
        'locked_by' => 'worker-still-working',
    ]);

    $this->artisan('publishing:dispatch-due')->assertSuccessful();

    expect($fresh->fresh()->locked_by)->toBe('worker-still-working');
});

// ------------------------------------------------------------------ batching

it('queues no more than the configured batch', function (): void {
    Queue::fake();

    config(['publishing.dispatch_batch_size' => 2]);

    /*
     | A distinct account per target: post_targets is unique on
     | (post_id, social_account_id), because one post goes to a given account
     | exactly once. Three targets means three destinations.
     */
    foreach (range(1, 3) as $i) {
        $account = SocialAccount::factory()->forCustomer($this->brand)->create();

        PostTarget::factory()->targeting($this->post, $account)->create([
            'status' => TargetStatus::Scheduled,
            'scheduled_at' => now()->subMinute(),
        ]);
    }

    $this->artisan('publishing:dispatch-due');

    Queue::assertPushed(PublishPostTarget::class, 2);
});
