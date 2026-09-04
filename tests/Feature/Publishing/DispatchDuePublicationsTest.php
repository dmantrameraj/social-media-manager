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
use App\Domain\Tenancy\Enums\TenantStatus;
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

// --------------------------------------------------------------- who may publish

it('does not publish for a suspended agency', function (): void {
    /*
     | TenantStatus::permitsPublishing() encoded this rule from Phase 1 and had
     | no caller. Its sibling permitsProductAccess() is enforced by
     | EnsureTenantActive -- but that is HTTP middleware, and publishing runs on
     | a schedule and a queue where no middleware applies, so an agency that
     | stopped paying kept receiving the core paid service.
     */
    Queue::fake();
    dueTarget();

    $this->tenant->forceFill(['status' => TenantStatus::Suspended->value])->save();

    $this->artisan('publishing:dispatch-due')->assertSuccessful();

    Queue::assertNothingPushed();
});

it('leaves posts scheduled for a suspended agency rather than failing them', function (): void {
    // Reinstating a tenant should resume their schedule, not make them
    // rebuild it.
    Queue::fake();
    $target = dueTarget();

    $this->tenant->forceFill(['status' => TenantStatus::Suspended->value])->save();
    $this->artisan('publishing:dispatch-due')->assertSuccessful();

    expect($target->fresh()->status)->toBe(TargetStatus::Scheduled);

    // And it goes out once they are paying again.
    $this->tenant->forceFill(['status' => TenantStatus::Active->value])->save();
    $this->artisan('publishing:dispatch-due')->assertSuccessful();

    Queue::assertPushed(PublishPostTarget::class);
});

it('still publishes during grace when the config allows it', function (): void {
    /*
     | Grace deliberately keeps publishing by default: cutting off a client's
     | posts because an agency's card expired damages a relationship grace
     | exists to protect. docs/09-BILLING.md section 5.
     */
    config()->set('billing.publish_during_grace', true);

    Queue::fake();
    dueTarget();

    $this->tenant->forceFill(['status' => TenantStatus::Grace->value])->save();

    $this->artisan('publishing:dispatch-due')->assertSuccessful();

    Queue::assertPushed(PublishPostTarget::class);
});

it('stops publishing during grace when the config forbids it', function (): void {
    // The flag is read per run, so switching it takes effect without a deploy.
    config()->set('billing.publish_during_grace', false);

    Queue::fake();
    dueTarget();

    $this->tenant->forceFill(['status' => TenantStatus::Grace->value])->save();

    $this->artisan('publishing:dispatch-due')->assertSuccessful();

    Queue::assertNothingPushed();
});

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
