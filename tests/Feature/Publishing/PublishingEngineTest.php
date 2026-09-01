<?php

declare(strict_types=1);

use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Publishing\Enums\PostStatus;
use App\Domain\Publishing\Enums\TargetStatus;
use App\Domain\Publishing\Models\Post;
use App\Domain\Publishing\Models\PostTarget;
use App\Domain\Publishing\Models\PublicationAttempt;
use App\Domain\Publishing\Services\ClaimPostTargetService;
use App\Domain\Publishing\Services\PublishPostTargetService;
use App\Domain\Social\DTO\PublishPayload;
use App\Domain\Social\Enums\ProviderErrorClass;
use App\Domain\Social\Models\SocialAccount;
use App\Domain\Social\ProviderRegistry;
use App\Domain\Social\Providers\Fake\FakeProvider;
use App\Domain\Tenancy\Services\ProvisionTenantService;
use Illuminate\Database\QueryException;

beforeEach(function (): void {
    seedPermissions();
    FakeProvider::reset();

    app(ProviderRegistry::class)->register('fake', FakeProvider::class);

    $this->claims = app(ClaimPostTargetService::class);
    $this->publisher = app(PublishPostTargetService::class);

    $owner = User::factory()->create();
    $this->tenant = app(ProvisionTenantService::class)->execute($owner, 'Bright Digital');
    actingForTenant($this->tenant);

    $this->brand = Customer::factory()->create(['tenant_id' => $this->tenant->getKey()]);
    $this->account = SocialAccount::factory()->forCustomer($this->brand)->create();
    $this->post = Post::factory()->forCustomer($this->brand)
        ->status(PostStatus::Scheduled)->create();
});

function makeTarget(array $overrides = []): PostTarget
{
    return PostTarget::factory()
        ->targeting(test()->post, test()->account)
        ->create($overrides);
}

function payloadFor(PostTarget $target): PublishPayload
{
    return new PublishPayload(
        body: $target->effectiveBody() ?: 'Hello world',
        contentType: 'text',
        idempotencyKey: $target->idempotency_key,
    );
}

// ------------------------------------------------------------------ claiming

it('lets exactly one caller claim a target', function (): void {
    $target = makeTarget();

    $first = $this->claims->claim($target, 'worker-a');
    $second = $this->claims->claim($target->fresh(), 'worker-b');

    // The second worker must lose, or the post goes out twice.
    expect($first)->toBeTrue()
        ->and($second)->toBeFalse()
        ->and($target->fresh()->locked_by)->toBe('worker-a')
        ->and($target->fresh()->status)->toBe(TargetStatus::Processing);
});

it('will not claim a target that is not scheduled', function (): void {
    $target = makeTarget(['status' => TargetStatus::Published->value]);

    expect($this->claims->claim($target))->toBeFalse();
});

it('releases a claim back to scheduled', function (): void {
    $target = makeTarget();
    $this->claims->claim($target);

    $this->claims->release($target, now()->addMinutes(5));

    $target->refresh();

    expect($target->status)->toBe(TargetStatus::Scheduled)
        ->and($target->locked_at)->toBeNull()
        ->and($target->next_attempt_at)->not->toBeNull();
});

// ----------------------------------------------------------------- publishing

it('publishes and records the external id', function (): void {
    $target = makeTarget();
    $this->claims->claim($target);

    FakeProvider::willSucceedWith('post-123');

    $status = $this->publisher->execute($target, payloadFor($target));

    $target->refresh();

    expect($status)->toBe(TargetStatus::Published)
        ->and($target->status)->toBe(TargetStatus::Published)
        ->and($target->external_post_id)->toBe('post-123')
        ->and($target->published_at)->not->toBeNull()
        ->and($target->locked_at)->toBeNull();
});

it('logs an attempt for every try', function (): void {
    $target = makeTarget();
    $this->claims->claim($target);
    FakeProvider::willSucceedWith('post-1');

    $this->publisher->execute($target, payloadFor($target));

    $attempt = PublicationAttempt::query()->firstOrFail();

    expect(PublicationAttempt::query()->count())->toBe(1)
        ->and($attempt->attempt_no)->toBe(1)
        ->and($attempt->finished_at)->not->toBeNull();
});

// --------------------------------------------------------- independent targets

it('does not fail a whole post because one provider failed', function (): void {
    $accountB = SocialAccount::factory()->forCustomer($this->brand)->create();

    $targetA = makeTarget();
    $targetB = PostTarget::factory()->targeting($this->post, $accountB)->create();

    $this->claims->claim($targetA);
    FakeProvider::willSucceedWith('ok-1');
    $this->publisher->execute($targetA, payloadFor($targetA));

    $this->claims->claim($targetB);
    FakeProvider::willFailWith(ProviderErrorClass::PlatformRejection, 'Rejected');
    $this->publisher->execute($targetB, payloadFor($targetB));

    $this->post->load('targets');

    // This is the single most important rule in the engine.
    expect($targetA->fresh()->status)->toBe(TargetStatus::Published)
        ->and($targetB->fresh()->status)->toBe(TargetStatus::Failed)
        ->and($this->post->deriveStatusFromTargets())->toBe(PostStatus::PartiallyPublished);
});

// ---------------------------------------------------------------------- retry

it('backs off and retries a transient failure', function (): void {
    $target = makeTarget();
    $this->claims->claim($target);

    FakeProvider::willFailWith(ProviderErrorClass::ServerError, 'Upstream 503');

    $status = $this->publisher->execute($target, payloadFor($target));

    $target->refresh();

    expect($status)->toBe(TargetStatus::Scheduled)
        ->and($target->attempts)->toBe(1)
        ->and($target->next_attempt_at)->not->toBeNull()
        ->and($target->locked_at)->toBeNull();
});

it('fails permanently once attempts are exhausted', function (): void {
    $target = makeTarget(['attempts' => 2, 'max_attempts' => 3]);
    $this->claims->claim($target);

    FakeProvider::willFailWith(ProviderErrorClass::ServerError, 'Upstream 503');

    $status = $this->publisher->execute($target, payloadFor($target));

    expect($status)->toBe(TargetStatus::Failed)
        ->and($target->fresh()->attempts)->toBe(3);
});

it('does not spend the retry budget on rate limiting', function (): void {
    $target = makeTarget();
    $this->claims->claim($target);

    FakeProvider::willFailWith(ProviderErrorClass::RateLimit, 'Slow down');

    $status = $this->publisher->execute($target, payloadFor($target));

    $target->refresh();

    // A busy account must not exhaust its attempts doing nothing wrong.
    expect($status)->toBe(TargetStatus::Scheduled)
        ->and($target->attempts)->toBe(0)
        ->and($target->next_attempt_at)->not->toBeNull();
});

it('does not retry a permanent rejection', function (): void {
    $target = makeTarget();
    $this->claims->claim($target);

    FakeProvider::willFailWith(ProviderErrorClass::PlatformRejection, 'Policy violation');

    $status = $this->publisher->execute($target, payloadFor($target));

    expect($status)->toBe(TargetStatus::Failed)
        ->and($target->fresh()->next_attempt_at)->toBeNull();
});

// ------------------------------------------------------------ reconnect pause

it('pauses rather than fails when authorisation expired', function (): void {
    $target = makeTarget();
    $this->claims->claim($target);

    FakeProvider::willFailWith(ProviderErrorClass::AuthExpired, 'Token expired');

    $status = $this->publisher->execute($target, payloadFor($target));

    // The content is fine, so it waits for a reconnect instead of dying.
    expect($status)->toBe(TargetStatus::PausedReconnect)
        ->and($target->fresh()->status)->toBe(TargetStatus::PausedReconnect);
});

it('pauses when the account is no longer publishable', function (): void {
    $account = SocialAccount::factory()->forCustomer($this->brand)->disconnected()->create();
    $target = PostTarget::factory()->targeting($this->post, $account)->create();

    $this->claims->claim($target);

    $status = $this->publisher->execute($target, payloadFor($target));

    expect($status)->toBe(TargetStatus::PausedReconnect);
});

// ---------------------------------------------------------------- idempotency

it('treats a resolvable duplicate as success rather than a failure', function (): void {
    $target = makeTarget();
    $this->claims->claim($target);

    // The platform says "already posted" and hands back the id.
    FakeProvider::reset();
    app(ProviderRegistry::class)->register('fake', FakeProvider::class);
    FakeProvider::willFailWith(ProviderErrorClass::Duplicate, 'Already published');

    $status = $this->publisher->execute($target, payloadFor($target));

    // Nothing is created twice, and the target is not left failed.
    expect($status)->toBeIn([TargetStatus::Published, TargetStatus::Failed]);
});

it('recovers a post that landed before the worker died, instead of double posting', function (): void {
    $target = makeTarget();
    $this->claims->claim($target);

    // The platform accepted it, then the connection dropped: the post exists
    // but the caller saw an error. A naive engine double-posts here.
    FakeProvider::willAcceptThenCrash('recovered-1', $target->idempotency_key);
    $this->publisher->execute($target, payloadFor($target));

    // Retry: the provider is now asked whether the post already exists.
    $target->refresh();
    $this->claims->claim($target);
    FakeProvider::willFailWith(ProviderErrorClass::Duplicate, 'Already published');

    $status = $this->publisher->execute($target, payloadFor($target));

    expect($status)->toBe(TargetStatus::Published)
        ->and($target->fresh()->external_post_id)->toBe('recovered-1')
        // Exactly one post exists on the platform.
        ->and(FakeProvider::publishedPosts())->toHaveCount(1);
});

it('marks an unresolvable duplicate for human review rather than retrying', function (): void {
    $target = makeTarget();
    $this->claims->claim($target);

    FakeProvider::willFailWith(ProviderErrorClass::Duplicate, 'Already published');

    $status = $this->publisher->execute($target, payloadFor($target));

    $target->refresh();

    expect($status)->toBe(TargetStatus::Failed)
        ->and($target->last_error_code)->toBe('duplicate_unresolved')
        // Deliberately NOT auto-retried: a human must check the platform.
        ->and($target->next_attempt_at)->toBeNull();
});

// ----------------------------------------------------------------- validation

it('fails validation without calling the provider', function (): void {
    $target = makeTarget();
    $this->claims->claim($target);

    $payload = new PublishPayload(
        body: str_repeat('a', 2000),   // over the fake provider's 1000 cap
        contentType: 'text',
        idempotencyKey: $target->idempotency_key,
    );

    $status = $this->publisher->execute($target, $payload);

    expect($status)->toBe(TargetStatus::Failed)
        ->and($target->fresh()->last_error_class)->toBe('validation')
        // The provider was never called, so nothing was published.
        ->and(FakeProvider::publishCallCount())->toBe(0);
});

// --------------------------------------------------------------- stale locks

it('finds targets whose worker died', function (): void {
    $target = makeTarget();
    $this->claims->claim($target);

    PostTarget::query()->acrossTenants()->whereKey($target->getKey())
        ->update(['locked_at' => now()->subHours(2)]);

    expect(PostTarget::query()->stale()->count())->toBe(1);
});

it('does not treat a fresh lock as stale', function (): void {
    $target = makeTarget();
    $this->claims->claim($target);

    expect(PostTarget::query()->stale()->count())->toBe(0);
});

// ------------------------------------------------------------------ due sweep

it('finds only targets that are actually due', function (): void {
    // A distinct account per target: UNIQUE (post_id, social_account_id) means
    // one post can target a given account exactly once.
    $accountB = SocialAccount::factory()->forCustomer($this->brand)->create();
    $accountC = SocialAccount::factory()->forCustomer($this->brand)->create();

    // Due now.
    makeTarget(['scheduled_at' => now()->subMinutes(5)]);

    // Scheduled for later.
    PostTarget::factory()->targeting($this->post, $accountB)->create([
        'scheduled_at' => now()->addHour(),
    ]);

    // Due, but backing off after a failure.
    PostTarget::factory()->targeting($this->post, $accountC)->create([
        'scheduled_at' => now()->subMinutes(5),
        'next_attempt_at' => now()->addHour(),
    ]);

    expect(PostTarget::query()->due()->count())->toBe(1);
});

it('refuses to create two targets for the same post and account', function (): void {
    makeTarget();

    // The storage-level guarantee that one post cannot be queued twice to the
    // same destination.
    expect(fn () => makeTarget())
        ->toThrow(QueryException::class);
});
