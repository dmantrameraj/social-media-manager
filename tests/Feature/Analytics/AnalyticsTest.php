<?php

declare(strict_types=1);

use App\Domain\Analytics\Models\PostMetric;
use App\Domain\Analytics\Services\RecordPostMetricsService;
use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Publishing\Enums\PostStatus;
use App\Domain\Publishing\Enums\TargetStatus;
use App\Domain\Publishing\Models\Post;
use App\Domain\Publishing\Models\PostTarget;
use App\Domain\Social\Models\SocialAccount;
use App\Domain\Tenancy\Services\ProvisionTenantService;

/*
 | Reporting on what the work achieved.
 |
 | `analytics.view` has been in the permission catalogue since Step 5 and the
 | Analyst role has existed to hold it, governing nothing: no table, no
 | collection and no screen. An agency could publish for a client all month and
 | had nothing to show them at the end of it.
 */

beforeEach(function (): void {
    seedPermissions();

    $owner = User::factory()->create();
    $this->tenant = app(ProvisionTenantService::class)->execute($owner, 'Bright Digital');
    $this->owner = $owner->fresh();
    actingForTenant($this->tenant);

    $this->brand = Customer::factory()->create([
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'Roast House',
    ]);
    $this->account = SocialAccount::factory()->forCustomer($this->brand)->create();
});

/** A published target, which is the only thing worth polling. */
function publishedTarget(?Customer $brand = null): PostTarget
{
    $brand ??= test()->brand;

    $post = Post::factory()->forCustomer($brand)->status(PostStatus::Published)->create();

    return PostTarget::factory()
        ->targeting($post, SocialAccount::factory()->forCustomer($brand)->create())
        ->create([
            'status' => TargetStatus::Published,
            'external_post_id' => 'external-'.fake()->unique()->numberBetween(1, 99999),
        ]);
}

// ------------------------------------------------------------------ recording

it('records normalised metrics and keeps the raw payload', function (): void {
    /*
     | Both, deliberately. Normalisation is lossy and irreversible: a network
     | that renames a field is discovered months later, when re-polling is
     | impossible because the API has aged the data out.
     */
    $target = publishedTarget();

    $metric = app(RecordPostMetricsService::class)->record(
        $target,
        ['impressions' => 1000, 'likes' => 40],
        ['impressions' => 1000, 'likes' => 40, 'provider_only_field' => 'kept'],
    );

    expect($metric->impressions)->toBe(1000)
        ->and($metric->likes)->toBe(40)
        ->and($metric->raw['provider_only_field'])->toBe('kept');
});

it('leaves an unreported metric null rather than zero', function (): void {
    /*
     | A network that does not report saves is not one reporting zero saves.
     | Coercing the first into the second makes every average that touches it
     | quietly wrong, and a 0 in a client report reads as "this failed".
     */
    $target = publishedTarget();

    $metric = app(RecordPostMetricsService::class)->record($target, ['impressions' => 500]);

    expect($metric->impressions)->toBe(500)
        ->and($metric->saves)->toBeNull()
        ->and($metric->video_views)->toBeNull();
});

it('updates in place when a collection is retried', function (): void {
    // A retried run must not double every figure on the dashboard, which is
    // what makes an analytics screen untrustworthy rather than merely wrong.
    $target = publishedTarget();
    $service = app(RecordPostMetricsService::class);
    $at = now();

    $service->record($target, ['impressions' => 100], null, $at);
    $service->record($target, ['impressions' => 180], null, $at);

    expect(PostMetric::query()->count())->toBe(1)
        ->and(PostMetric::query()->sole()->impressions)->toBe(180);
});

// -------------------------------------------------------------- the arithmetic

it('counts a post once however often it was polled', function (): void {
    /*
     | Analytics are re-polled as a post matures. Summing every collection
     | counts the same post several times over -- the usual reason an
     | analytics screen produces figures nobody can reconcile.
     */
    $target = publishedTarget();
    $service = app(RecordPostMetricsService::class);

    $service->record($target, ['impressions' => 100], null, now()->subDays(2));
    $service->record($target, ['impressions' => 400], null, now()->subDay());
    $service->record($target, ['impressions' => 900], null, now());

    $this->actingAs($this->owner)
        ->get(route('agency.analytics.index', ['days' => 30]))
        ->assertOk()
        // The latest figure, not 1,400.
        ->assertSee('900')
        ->assertDontSee('1,400');
});

it('does not report a figure from outside the window', function (): void {
    /*
     | The bug this scope exists to avoid: taking the newest row overall and
     | then filtering by date picks a row from outside the window for a post
     | still being polled, and reports last month as this month. Worse than
     | reporting nothing, because it looks like an answer.
     */
    $target = publishedTarget();
    $service = app(RecordPostMetricsService::class);

    $service->record($target, ['impressions' => 5000], null, now()->subDays(90));

    $this->actingAs($this->owner)
        ->get(route('agency.analytics.index', ['days' => 7]))
        ->assertOk()
        ->assertDontSee('5,000');
});

// ------------------------------------------------------------------- the screen

it('refuses the dashboard without the permission', function (): void {
    // Content Creator has posts and media, not analytics.
    $member = memberWithRole($this->tenant, 'Content Creator');

    $this->actingAs($member)
        ->get(route('agency.analytics.index'))
        ->assertForbidden();
});

it('lets an analyst read it', function (): void {
    // The role has existed since Step 5 holding a permission that governed
    // nothing.
    $analyst = memberWithRole($this->tenant, 'Analyst');

    $this->actingAs($analyst)
        ->get(route('agency.analytics.index'))
        ->assertOk();
});

it('never shows another agency figures', function (): void {
    [$rival] = provisionTenant('Rival Agency');

    /*
     | Built INSIDE the rival's context. provisionTenant() does not switch
     | context, and the recorder reads $target->post through the ordinary
     | scope -- so creating the foreign rows while Bright Digital is active
     | makes the post unreadable and the test fails on its own setup rather
     | than on the isolation it means to prove.
     */
    actingForTenant($rival);

    $foreignBrand = Customer::factory()->create(['tenant_id' => $rival->getKey()]);
    $foreignTarget = publishedTarget($foreignBrand);

    app(RecordPostMetricsService::class)
        ->record($foreignTarget, ['impressions' => 987654]);

    actingForTenant($this->tenant);

    $this->actingAs($this->owner)
        ->get(route('agency.analytics.index'))
        ->assertOk()
        ->assertDontSee('987,654');
});

it('ignores a brand id the viewer cannot reach', function (): void {
    /*
     | Supplying an id must not widen what somebody sees. The selection is
     | intersected with the brands they can reach rather than trusted.
     */
    $other = Customer::factory()->create(['tenant_id' => $this->tenant->getKey()]);
    $target = publishedTarget($other);

    app(RecordPostMetricsService::class)->record($target, ['impressions' => 424242]);

    // Content Creator sees only assigned brands, and is assigned to none.
    $member = memberWithRole($this->tenant, 'Analyst');

    $this->actingAs($member)
        ->get(route('agency.analytics.index', ['brand' => $other->getKey()]))
        ->assertOk()
        ->assertDontSee('424,242');
});

it('rejects a period longer than a year', function (): void {
    // Bounded so one request cannot scan an entire history.
    $this->actingAs($this->owner)
        ->get(route('agency.analytics.index', ['days' => 5000]))
        ->assertSessionHasErrors('days');
});

it('says so plainly when nothing has been collected', function (): void {
    $this->actingAs($this->owner)
        ->get(route('agency.analytics.index'))
        ->assertOk()
        ->assertSee('Nothing collected yet');
});
