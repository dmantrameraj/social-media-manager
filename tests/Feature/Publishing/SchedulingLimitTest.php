<?php

declare(strict_types=1);

use App\Domain\Billing\Entitlements\EntitlementResolver;
use App\Domain\Billing\Entitlements\Exceptions\EntitlementExceeded;
use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Publishing\Enums\PostStatus;
use App\Domain\Publishing\Models\Post;
use App\Domain\Publishing\Workflow\PostStatusMachine;
use App\Domain\Tenancy\Services\ProvisionTenantService;

/*
 | The plan limit on scheduling.
 |
 | posts.scheduled_per_month has been in the entitlement catalogue since Phase
 | 1, sold on every plan, with its usage counter hardcoded to 0 and a "Phase 3"
 | note beside it. Every plan therefore sold a limit that nothing enforced --
 | the same shape as social_accounts.max before it, and the only kind of gap in
 | this repository that costs money rather than credibility.
 */

beforeEach(function (): void {
    seedPermissions();

    $this->owner = User::factory()->create();
    $this->tenant = app(ProvisionTenantService::class)->execute($this->owner, 'Bright Digital');
    $this->owner = $this->owner->fresh();

    actingForTenant($this->tenant);

    $this->brand = Customer::factory()->create(['tenant_id' => $this->tenant->getKey()]);
    $this->machine = app(PostStatusMachine::class);
});

/** A draft ready to be scheduled. */
function draftPost(): Post
{
    return Post::factory()
        ->forCustomer(test()->brand)
        ->status(PostStatus::Draft)
        ->create(['scheduled_at' => now()->addDay()]);
}

/** Schedule $count posts, returning the last. */
function scheduleMany(int $count): Post
{
    $post = null;

    for ($i = 0; $i < $count; $i++) {
        $post = draftPost();
        test()->machine->transition($post, PostStatus::Scheduled, test()->owner);
    }

    return $post;
}

it('counts a scheduled post against the plan', function (): void {
    givePlanLimit($this->tenant->getKey(), 'posts.scheduled_per_month', 5);

    scheduleMany(3);

    expect(app(EntitlementResolver::class)
        ->currentUsage($this->tenant, 'posts.scheduled_per_month'))
        ->toBe(3);
});

it('refuses to schedule past the plan limit', function (): void {
    givePlanLimit($this->tenant->getKey(), 'posts.scheduled_per_month', 2);

    scheduleMany(2);

    $this->machine->transition(draftPost(), PostStatus::Scheduled, $this->owner);
})->throws(EntitlementExceeded::class);

it('does not charge again for rescheduling the same post', function (): void {
    /*
     | Usage counts DISTINCT posts. An agency tidying its calendar writes an
     | approval row per move, and charging per row would mean housekeeping ran
     | a tenant out of plan.
     */
    givePlanLimit($this->tenant->getKey(), 'posts.scheduled_per_month', 2);

    $post = scheduleMany(1);

    // Back to draft and out again -- two more approval rows, one post.
    $this->machine->transition($post, PostStatus::Draft, $this->owner);
    $this->machine->transition($post->refresh(), PostStatus::Scheduled, $this->owner);

    expect(app(EntitlementResolver::class)
        ->currentUsage($this->tenant, 'posts.scheduled_per_month'))
        ->toBe(1);
});

it('lets a failed post be retried at the limit', function (): void {
    /*
     | The post has already been counted, so recovering it must not be the
     | thing that is refused. A tenant at their limit with a failed post they
     | cannot retry would have paid for a post that never went out.
     */
    givePlanLimit($this->tenant->getKey(), 'posts.scheduled_per_month', 1);

    $post = scheduleMany(1);
    $post->forceFill(['status' => PostStatus::Failed->value])->save();

    $this->machine->transition($post->refresh(), PostStatus::Scheduled, $this->owner);

    expect($post->refresh()->status)->toBe(PostStatus::Scheduled);
});

it('does not count another agency scheduling', function (): void {
    givePlanLimit($this->tenant->getKey(), 'posts.scheduled_per_month', 2);

    [$rival, $rivalOwner] = provisionTenant('Rival Agency');
    actingForTenant($rival);

    $rivalBrand = Customer::factory()->create(['tenant_id' => $rival->getKey()]);

    for ($i = 0; $i < 5; $i++) {
        $post = Post::factory()->forCustomer($rivalBrand)
            ->status(PostStatus::Draft)->create(['scheduled_at' => now()->addDay()]);

        $this->machine->transition($post, PostStatus::Scheduled, $rivalOwner);
    }

    actingForTenant($this->tenant);

    expect(app(EntitlementResolver::class)
        ->currentUsage($this->tenant, 'posts.scheduled_per_month'))
        ->toBe(0);
});

it('ignores scheduling from a previous period', function (): void {
    givePlanLimit($this->tenant->getKey(), 'posts.scheduled_per_month', 2);

    scheduleMany(2);

    // The allowance is per period, so last month's posts are not this month's
    // problem. With no subscription row the window is the calendar month.
    $this->travelTo(now()->addMonthNoOverflow()->startOfMonth()->addDay());

    expect(app(EntitlementResolver::class)
        ->currentUsage($this->tenant, 'posts.scheduled_per_month'))
        ->toBe(0);
});
