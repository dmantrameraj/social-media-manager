<?php

declare(strict_types=1);

use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Publishing\Enums\PostStatus;
use App\Domain\Publishing\Models\Post;
use App\Domain\Publishing\Models\RecurringPostRule;
use App\Domain\Publishing\Services\MaterialiseRecurringPostsService;
use App\Domain\Social\Enums\AccountStatus;
use App\Domain\Social\Models\SocialAccount;
use App\Domain\Tenancy\Services\ProvisionTenantService;
use Illuminate\Support\Carbon;

/*
 | Recurring posts, per docs/06-PUBLISHING-ENGINE.md §11.
 |
 | A rule is a template plus a cadence and publishes nothing itself. What is
 | worth testing is therefore not "does it post" but the three things that go
 | wrong quietly:
 |
 |   - the cadence arithmetic, which a DST boundary can silently skew
 |   - idempotency, because the sweeper runs every night for ever
 |   - the approval gate, because a standing rule must not be a way around it
 */

beforeEach(function (): void {
    seedPermissions();

    $this->travelTo(Carbon::parse('2026-03-01 06:00:00', 'UTC'));

    $this->owner = User::factory()->create();
    $this->tenant = app(ProvisionTenantService::class)->execute($this->owner, 'Bright Digital');
    $this->owner = $this->owner->fresh();

    actingForTenant($this->tenant);

    $this->brand = Customer::factory()->create([
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'Roast House',
        'timezone' => 'UTC',
        // Approval off by default, so the scheduling path is the one under
        // test. The gate gets its own test below.
        'settings' => ['approval_required' => false],
    ]);

    $this->materialiser = app(MaterialiseRecurringPostsService::class);
});

/** @param  array<string, mixed>  $attributes */
function rule(array $attributes = []): RecurringPostRule
{
    return RecurringPostRule::factory()
        ->forCustomer(test()->brand)
        ->create(array_merge([
            'created_by_user_id' => test()->owner->getKey(),
            'starts_on' => '2026-03-01',
        ], $attributes));
}

/** @return list<string> the occurrence dates that became posts */
function occurrenceDates(): array
{
    return Post::query()
        ->whereNotNull('occurrence_date')
        ->orderBy('occurrence_date')
        ->pluck('occurrence_date')
        ->map(fn ($date): string => Carbon::parse((string) $date)->toDateString())
        ->all();
}

it('turns a weekly rule into posts across the horizon', function (): void {
    /*
     | Sundays. 2026-03-01 is one, and the horizon is 60 days, to 2026-04-30.
     |
     | 7, not Carbon::SUNDAY. The column holds ISO-8601 weekday numbers and
     | Carbon::SUNDAY is 0, which matches no day and produces an active rule
     | that generates nothing at all -- so the form request validates 1..7.
     */
    $rule = rule(['frequency' => 'weekly', 'weekdays' => [7], 'interval' => 1]);

    $result = $this->materialiser->execute($rule);

    expect(occurrenceDates())->toBe([
        '2026-03-01', '2026-03-08', '2026-03-15', '2026-03-22', '2026-03-29',
        '2026-04-05', '2026-04-12', '2026-04-19', '2026-04-26',
    ])->and($result['created'])->toBe(9);

    // Bounded, which is the entire point of a horizon. Nothing in May.
    expect(Post::query()->where('occurrence_date', '>', '2026-04-30')->exists())->toBeFalse();
});

it('does not skip a week across a daylight-saving boundary', function (): void {
    /*
     | THE REGRESSION THIS FILE EXISTS FOR.
     |
     | US DST begins on 2026-03-08. Two local midnights either side of it are
     | 23 hours apart, so a signed float diffInDays returns 13.958 for what is
     | plainly a fortnight -- and `% 7`, which casts to int, reads that as 13
     | and the rule silently skips 2026-03-15.
     |
     | A fortnightly rule spanning the boundary is the shape that exposes it:
     | nobody notices a missing post until a client asks where it went.
     */
    $this->brand->update(['timezone' => 'America/New_York']);

    $rule = rule([
        'frequency' => 'weekly',
        'weekdays' => [7],
        'interval' => 2,
        'timezone' => 'America/New_York',
    ]);

    $this->materialiser->execute($rule);

    expect(occurrenceDates())->toBe([
        '2026-03-01', '2026-03-15', '2026-03-29', '2026-04-12', '2026-04-26',
    ]);
});

it('clamps a monthly rule to the length of a short month', function (): void {
    /*
     | A rule set to the 31st must still fire in April, which has 30 days.
     | Skipping the month would drop content an agency believes is scheduled.
     */
    $rule = rule(['frequency' => 'monthly', 'day_of_month' => 31, 'weekdays' => null]);

    $this->materialiser->execute($rule);

    expect(occurrenceDates())->toBe(['2026-03-31', '2026-04-30']);
});

it('counts a daily interval from the start date', function (): void {
    $rule = rule(['frequency' => 'daily', 'interval' => 3, 'weekdays' => null]);

    $this->materialiser->execute($rule);

    expect(occurrenceDates())->toContain('2026-03-01', '2026-03-04', '2026-03-07')
        ->not->toContain('2026-03-02', '2026-03-03');
});

it('creates nothing the second time it runs', function (): void {
    $rule = rule(['frequency' => 'daily', 'weekdays' => null]);

    $first = $this->materialiser->execute($rule);
    $before = Post::query()->count();

    $second = $this->materialiser->execute($rule->fresh());

    expect($first['created'])->toBeGreaterThan(0)
        ->and($second['created'])->toBe(0)
        ->and(Post::query()->count())->toBe($before);
});

it('does not resurrect an occurrence somebody deleted', function (): void {
    /*
     | A rule arguing with a person about whether a post should exist is a rule
     | that loses. Deleting next Tuesday's post has to mean something.
     */
    $rule = rule(['frequency' => 'daily', 'weekdays' => null]);
    $this->materialiser->execute($rule);

    $post = Post::query()->where('occurrence_date', '2026-03-05')->sole();
    $post->delete();

    // Move the marker back, so the window is genuinely reconsidered rather
    // than skipped by materialised_through.
    $rule->forceFill(['materialised_through' => null])->save();

    $this->materialiser->execute($rule->fresh());

    expect(Post::query()->where('occurrence_date', '2026-03-05')->exists())->toBeFalse();
});

it('never backfills a rule that started in the past', function (): void {
    $rule = rule(['frequency' => 'daily', 'weekdays' => null, 'starts_on' => '2026-01-01']);

    $this->materialiser->execute($rule);

    // January and February are not owed to anybody, and a post dated in the
    // past is one the engine publishes on its next sweep.
    expect(Post::query()->where('occurrence_date', '<', '2026-03-01')->exists())->toBeFalse()
        ->and(occurrenceDates())->toContain('2026-03-01');
});

it('skips today when the hour has already gone', function (): void {
    // 14:00; the rule fires at 09:00, which has passed.
    $this->travelTo(Carbon::parse('2026-03-01 14:00:00', 'UTC'));

    $rule = rule(['frequency' => 'daily', 'weekdays' => null, 'time_of_day' => '09:00:00']);

    $this->materialiser->execute($rule);

    expect(occurrenceDates())->not->toContain('2026-03-01')
        ->and(occurrenceDates())->toContain('2026-03-02');
});

it('schedules occurrences for a brand that does not require approval', function (): void {
    givePlanLimit($this->tenant->getKey(), 'posts.scheduled_per_month', 500);

    $rule = rule(['frequency' => 'daily', 'weekdays' => null]);

    $result = $this->materialiser->execute($rule);

    expect($result['scheduled'])->toBe($result['created'])
        ->and(Post::query()->where('status', PostStatus::Scheduled->value)->count())
        ->toBe($result['created']);
});

it('stops scheduling at the plan limit but still creates the content', function (): void {
    /*
     | A daily rule over a 60-day horizon asks for more scheduled posts than a
     | small plan sells. The limit is real and PostStatusMachine enforces it --
     | but the right outcome is drafts waiting to be scheduled, not occurrences
     | that were never created. A tenant at their limit should find their
     | content, not a gap.
     */
    givePlanLimit($this->tenant->getKey(), 'posts.scheduled_per_month', 5);

    $rule = rule(['frequency' => 'daily', 'weekdays' => null]);

    $result = $this->materialiser->execute($rule);

    expect($result['created'])->toBeGreaterThan(5)
        ->and($result['scheduled'])->toBe(5)
        ->and(Post::query()->where('status', PostStatus::Draft->value)->count())
        ->toBe($result['created'] - 5);
});

it('leaves occurrences as drafts when the brand requires client approval', function (): void {
    /*
     | The gate. A standing rule must not become a way to put content into a
     | client's feed without them ever seeing it -- the same constraint CSV
     | import and autopilot are both held to.
     */
    $this->brand->update(['settings' => ['approval_required' => true]]);

    $rule = rule(['frequency' => 'daily', 'weekdays' => null]);

    $result = $this->materialiser->execute($rule);

    expect($result['created'])->toBeGreaterThan(0)
        ->and($result['scheduled'])->toBe(0)
        ->and(Post::query()->where('status', '!=', PostStatus::Draft->value)->exists())
        ->toBeFalse();
});

it('gives each occurrence a target for every publishable account', function (): void {
    $active = SocialAccount::factory()->create([
        'tenant_id' => $this->tenant->getKey(),
        'customer_id' => $this->brand->getKey(),
        'status' => AccountStatus::Active->value,
    ]);

    $disconnected = SocialAccount::factory()->create([
        'tenant_id' => $this->tenant->getKey(),
        'customer_id' => $this->brand->getKey(),
        'status' => AccountStatus::Disconnected->value,
    ]);

    $rule = rule(['frequency' => 'daily', 'weekdays' => null]);
    $rule->accounts()->attach([
        $active->getKey() => ['tenant_id' => $this->tenant->getKey()],
        $disconnected->getKey() => ['tenant_id' => $this->tenant->getKey()],
    ]);

    $this->materialiser->execute($rule->fresh());

    $post = Post::query()->where('occurrence_date', '2026-03-02')->sole();

    // A disconnected account is not a destination. A recurring rule should not be
    // the one path that gets to ignore that.
    expect($post->targets)->toHaveCount(1)
        ->and($post->targets->first()->social_account_id)->toBe($active->getKey());
});

it('stores the wall-clock time in the rule timezone as UTC', function (): void {
    $rule = rule([
        'frequency' => 'daily',
        'weekdays' => null,
        'time_of_day' => '09:00:00',
        'timezone' => 'Asia/Kolkata',
    ]);

    $this->materialiser->execute($rule);

    $post = Post::query()->where('occurrence_date', '2026-03-02')->sole();

    // 09:00 in Kolkata is 03:30 UTC. Storing local time is how a scheduled
    // post goes out five and a half hours early.
    expect($post->scheduled_at->toDateTimeString())->toBe('2026-03-02 03:30:00')
        ->and($post->timezone)->toBe('Asia/Kolkata');
});

it('schedules from the nightly sweep, with no request to set anything up', function (): void {
    /*
     | THE SECOND REGRESSION THIS FILE EXISTS FOR.
     |
     | The status machine asks $actor->can('posts.schedule'), and spatie's team
     | id is bound by the ResolveTenant middleware -- which a scheduled command
     | never runs. Without runAll() setting it, every check answers false, and
     | the sweep quietly leaves every occurrence a draft for ever while the same
     | code works perfectly from a controller.
     |
     | So this test deliberately strips BOTH the tenant context and the team id
     | first. Every other test here calls execute() after actingForTenant(),
     | which sets them -- and would therefore never have caught it.
     */
    givePlanLimit($this->tenant->getKey(), 'posts.scheduled_per_month', 500);

    rule(['frequency' => 'daily', 'weekdays' => null]);

    withoutTenantContext();
    setPermissionsTeamId(null);

    $result = app(MaterialiseRecurringPostsService::class)->runAll();

    expect($result['rules'])->toBe(1)
        ->and($result['created'])->toBeGreaterThan(0)
        ->and($result['scheduled'])->toBe($result['created']);
});

it('restores the surrounding tenant and team after a sweep', function (): void {
    // The sweeper walks every tenant. Leaving the last one bound would make
    // whatever ran next in the same process silently act as that agency.
    rule(['frequency' => 'daily', 'weekdays' => null]);

    $before = getPermissionsTeamId();

    app(MaterialiseRecurringPostsService::class)->runAll();

    expect(getPermissionsTeamId())->toBe($before);
});

it('ignores a paused rule and one that has ended', function (): void {
    rule(['frequency' => 'daily', 'weekdays' => null, 'is_active' => false]);
    rule(['frequency' => 'daily', 'weekdays' => null, 'ends_on' => '2026-02-01']);

    $result = $this->materialiser->runAll();

    expect($result['rules'])->toBe(0)
        ->and(Post::query()->count())->toBe(0);
});

it('stops a rule at its end date', function (): void {
    $rule = rule(['frequency' => 'daily', 'weekdays' => null, 'ends_on' => '2026-03-05']);

    $this->materialiser->execute($rule);

    expect(occurrenceDates())->toBe([
        '2026-03-01', '2026-03-02', '2026-03-03', '2026-03-04', '2026-03-05',
    ]);
});

it('marks the rule materialised even when the window produced nothing', function (): void {
    // Monthly on the 15th, but the rule ends before one arrives.
    $rule = rule([
        'frequency' => 'monthly',
        'day_of_month' => 15,
        'weekdays' => null,
        'ends_on' => '2026-03-10',
    ]);

    $this->materialiser->execute($rule);

    /*
     | The marker means "this window has been considered". Leaving it null
     | because nothing was created would make the sweeper rebuild the same
     | empty window every night for ever.
     */
    expect($rule->fresh()->materialised_through)->not->toBeNull()
        ->and(Post::query()->count())->toBe(0);
});

it('keeps the posts a deleted rule already produced', function (): void {
    /*
     | nullOnDelete, not cascade. Those posts are real content, some of it
     | already published, and deleting the rule is a statement about the
     | future rather than about the past.
     */
    $rule = rule(['frequency' => 'daily', 'weekdays' => null]);
    $this->materialiser->execute($rule);

    $count = Post::query()->count();
    $rule->forceDelete();

    expect(Post::query()->count())->toBe($count)
        ->and(Post::query()->whereNotNull('recurring_post_rule_id')->exists())->toBeFalse();
});

it('does not let one tenant see another tenant rule', function (): void {
    $rule = rule(['frequency' => 'daily', 'weekdays' => null]);

    $other = User::factory()->create();
    $otherTenant = app(ProvisionTenantService::class)->execute($other, 'Rival Agency');

    actingForTenant($otherTenant);

    expect(RecurringPostRule::query()->find($rule->getKey()))->toBeNull()
        ->and(RecurringPostRule::query()->count())->toBe(0);
});
