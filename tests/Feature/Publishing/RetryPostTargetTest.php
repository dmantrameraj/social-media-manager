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
use Illuminate\Support\Facades\DB;

/*
 | Sending a failed destination again.
 |
 | The engine has always been able to retry -- post_targets carries attempts,
 | max_attempts and next_attempt_at, publication_attempts records every try,
 | and ProviderErrorClass decides what is worth retrying. posts.retry has been
 | in the permission catalogue since Step 5.
 |
 | None of it was reachable. A post that failed showed one sentence and sat
 | there, so an agency whose client's content did not go out could see that it
 | had not gone out and do nothing. Found by the unreachable-code sweep, which
 | is the twenty-first time that pattern has turned up here.
 */

beforeEach(function (): void {
    seedPermissions();

    $this->owner = User::factory()->create();
    $this->tenant = app(ProvisionTenantService::class)->execute($this->owner, 'Bright Digital');
    $this->owner = $this->owner->fresh();

    actingForTenant($this->tenant);

    $this->brand = Customer::factory()->create(['tenant_id' => $this->tenant->getKey()]);
    $this->account = SocialAccount::factory()->forCustomer($this->brand)->create();
});

/** A post whose one destination failed. */
function failedTarget(
    TargetStatus $status = TargetStatus::Failed,
    PostStatus $postStatus = PostStatus::Failed,
): PostTarget {
    $post = Post::factory()->forCustomer(test()->brand)->status($postStatus)
        ->create(['scheduled_at' => now()->subHour()]);

    return PostTarget::factory()->targeting($post, test()->account)->status($status)->create([
        'scheduled_at' => now()->subHour(),
        'attempts' => 3,
        'max_attempts' => 3,
        'next_attempt_at' => now()->addDay(),
        'last_error_class' => 'network',
        'last_error_code' => '503',
        'last_error_message' => 'The network refused the post.',
    ]);
}

it('puts a failed destination back in the queue', function (): void {
    $target = failedTarget();

    asAgencyUser($this->owner)
        ->post(route('agency.posts.targets.retry', [$target->post, $target]))
        ->assertRedirect();

    expect($target->refresh()->status)->toBe(TargetStatus::Scheduled)
        // Cleared, so the sweeper takes it on the next pass rather than
        // honouring a backoff computed for the failure.
        ->and($target->next_attempt_at)->toBeNull()
        // The old failure is not this attempt's failure.
        ->and($target->last_error_message)->toBeNull();
});

it('resets the attempt counter rather than incrementing it', function (): void {
    /*
     | max_attempts bounds how hard the ENGINE tries on its own. A person
     | clicking retry has read the error and usually fixed the cause, and
     | giving them one grudging attempt out of an exhausted budget would mean
     | the button does nothing.
     */
    $target = failedTarget();

    expect($target->attempts)->toBe(3);

    asAgencyUser($this->owner)
        ->post(route('agency.posts.targets.retry', [$target->post, $target]));

    expect($target->refresh()->attempts)->toBe(0);
});

it('brings the post back in line with its targets', function (): void {
    // Otherwise the calendar shows a failed post that is about to publish.
    $target = failedTarget();

    asAgencyUser($this->owner)
        ->post(route('agency.posts.targets.retry', [$target->post, $target]));

    expect($target->post->refresh()->status)->toBe(PostStatus::Scheduled);
});

it('recovers a partially published post', function (): void {
    $target = failedTarget(postStatus: PostStatus::PartiallyPublished);

    asAgencyUser($this->owner)
        ->post(route('agency.posts.targets.retry', [$target->post, $target]));

    expect($target->post->refresh()->status)->toBe(PostStatus::Scheduled);
});

it('refuses a destination that has already published', function (): void {
    $target = failedTarget(TargetStatus::Published, PostStatus::Published);

    asAgencyUser($this->owner)
        ->post(route('agency.posts.targets.retry', [$target->post, $target]))
        ->assertSessionHas('error');

    expect($target->refresh()->status)->toBe(TargetStatus::Published);
});

it('refuses one nobody knows the outcome of', function (): void {
    /*
     | NeedsVerification exists precisely because a worker died mid-publish and
     | nobody knows whether the post went out. Retrying it is the blind retry
     | the engine documentation refuses to do, and resolving it safely needs
     | SupportsRecentPostLookup, which no real provider implements yet.
     |
     | A second copy on a client's feed is worse than a post still marked
     | failed.
     */
    $target = failedTarget(TargetStatus::NeedsVerification);

    asAgencyUser($this->owner)
        ->post(route('agency.posts.targets.retry', [$target->post, $target]))
        ->assertSessionHas('error');

    expect($target->refresh()->status)->toBe(TargetStatus::NeedsVerification);
});

it('refuses one that is publishing right now', function (): void {
    $target = failedTarget(TargetStatus::Processing, PostStatus::Processing);

    asAgencyUser($this->owner)
        ->post(route('agency.posts.targets.retry', [$target->post, $target]))
        ->assertSessionHas('error');

    expect($target->refresh()->status)->toBe(TargetStatus::Processing);
});

it('needs the retry permission', function (): void {
    $target = failedTarget();

    // A Content Creator writes posts; recovering a failed publication is a
    // different decision and the catalogue says so.
    asAgencyUser(memberWithRole($this->tenant, 'Content Creator'))
        ->post(route('agency.posts.targets.retry', [$target->post, $target]))
        ->assertForbidden();
});

it('will not retry a target belonging to another post', function (): void {
    /*
     | Route model binding resolves the post and the target independently, so
     | without an explicit check a valid target id from another post -- or
     | another brand -- would be accepted.
     */
    $mine = failedTarget();
    $other = failedTarget();

    asAgencyUser($this->owner)
        ->post(route('agency.posts.targets.retry', [$mine->post, $other]))
        ->assertNotFound();

    expect($other->refresh()->status)->toBe(TargetStatus::Failed);
});

it('cannot retry another agency post', function (): void {
    [$rival] = provisionTenant('Rival Agency');
    actingForTenant($rival);

    $rivalBrand = Customer::factory()->create(['tenant_id' => $rival->getKey()]);
    $rivalAccount = SocialAccount::factory()->forCustomer($rivalBrand)->create();
    $rivalPost = Post::factory()->forCustomer($rivalBrand)->status(PostStatus::Failed)->create();
    $rivalTarget = PostTarget::factory()->targeting($rivalPost, $rivalAccount)
        ->status(TargetStatus::Failed)->create();

    actingForTenant($this->tenant);

    asAgencyUser($this->owner)
        ->post(route('agency.posts.targets.retry', [$rivalPost, $rivalTarget]))
        ->assertNotFound();
});

it('records who sent it again', function (): void {
    $target = failedTarget();

    asAgencyUser($this->owner)
        ->post(route('agency.posts.targets.retry', [$target->post, $target]));

    expect(AuditLog::query()->where('action', 'post_target.retried')->exists())->toBeTrue();
});

it('lets a tenant at their plan limit recover a failure', function (): void {
    /*
     | The scheduling entitlement counts DISTINCT posts, so a retry of a post
     | already counted this period costs nothing. A tenant unable to recover a
     | post they had already paid for would have paid for a post that never
     | went out.
     */
    givePlanLimit($this->tenant->getKey(), 'posts.scheduled_per_month', 1);

    $target = failedTarget();

    DB::table('post_approvals')->insert([
        'tenant_id' => $this->tenant->getKey(),
        'post_id' => $target->post_id,
        'stage' => 'internal',
        'action' => 'transitioned',
        'actor_type' => 'user',
        'actor_id' => $this->owner->getKey(),
        'from_status' => PostStatus::Draft->value,
        'to_status' => PostStatus::Scheduled->value,
        'created_at' => now()->subDays(2),
    ]);

    asAgencyUser($this->owner)
        ->post(route('agency.posts.targets.retry', [$target->post, $target]))
        ->assertRedirect();

    expect($target->refresh()->status)->toBe(TargetStatus::Scheduled);
});

// --------------------------------------------------------- attempt history

it('shows why it failed to somebody who can retry', function (): void {
    /*
     | publication_attempts has recorded every try since Phase 3 and nothing
     | read it, so "that post failed" was the whole story available to whoever
     | had to fix it.
     */
    $target = failedTarget();

    DB::table('publication_attempts')->insert([
        'tenant_id' => $this->tenant->getKey(),
        'post_target_id' => $target->getKey(),
        'attempt_no' => 1,
        'started_at' => now()->subHour(),
        'finished_at' => now()->subHour(),
        'outcome' => 'retryable_failure',
        'http_status' => 503,
        'error_class' => 'network',
        'error_code' => 'unreachable',
        'error_message' => 'The network refused the post.',
        'created_at' => now()->subHour(),
    ]);

    asAgencyUser($this->owner)
        ->get(route('agency.posts.show', $target->post))
        ->assertOk()
        ->assertSee('Delivery attempts')
        ->assertSee('The network refused the post.');
});

it('keeps provider detail from somebody who cannot retry', function (): void {
    $target = failedTarget();

    DB::table('publication_attempts')->insert([
        'tenant_id' => $this->tenant->getKey(),
        'post_target_id' => $target->getKey(),
        'attempt_no' => 1,
        'started_at' => now()->subHour(),
        'outcome' => 'retryable_failure',
        'error_code' => 'a-provider-subcode',
        'error_message' => 'Raw provider detail.',
        'created_at' => now()->subHour(),
    ]);

    /*
     | An Analyst assigned to the brand: they can open the post, and they are
     | not the person recovering it. Provider detail is useful to whoever is
     | fixing the failure and noise to everyone else — which is the line the
     | permission has always been documented to draw.
     */
    $analyst = memberWithRole($this->tenant, 'Analyst');
    $this->brand->users()->attach($analyst->getKey());
    $analyst->forgetAssignedCustomers();

    asAgencyUser($analyst->fresh())
        ->get(route('agency.posts.show', $target->post))
        ->assertOk()
        ->assertDontSee('Delivery attempts')
        ->assertDontSee('a-provider-subcode');
});
