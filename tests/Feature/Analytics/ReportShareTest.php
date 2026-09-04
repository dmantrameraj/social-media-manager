<?php

declare(strict_types=1);

use App\Domain\Analytics\Models\PostMetric;
use App\Domain\Analytics\Models\ReportShare;
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
 | Getting a report out of the product.
 |
 | `reports.generate` and `reports.share` have been in the permission
 | catalogue since Step 5 governing nothing: an agency could see figures on a
 | screen and had no way to hand them to the client who paid for the work.
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
});

/** A published target carrying real figures. */
function reportedTarget(?Customer $brand = null, int $impressions = 1000): PostTarget
{
    $brand ??= test()->brand;

    $post = Post::factory()->forCustomer($brand)->status(PostStatus::Published)->create();

    $target = PostTarget::factory()
        ->targeting($post, SocialAccount::factory()->forCustomer($brand)->create())
        ->create([
            'status' => TargetStatus::Published,
            'external_post_id' => 'ext-'.fake()->unique()->numberBetween(1, 99999),
        ]);

    app(RecordPostMetricsService::class)->record($target, [
        'impressions' => $impressions,
        'likes' => 40,
    ]);

    return $target;
}

// -------------------------------------------------------------------- export

it('exports the window as a spreadsheet', function (): void {
    reportedTarget();

    $response = $this->actingAs($this->owner)
        ->get(route('agency.reports.export', ['brand' => $this->brand->getKey(), 'days' => 30]));

    $response->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');

    $csv = $response->streamedContent();

    expect($csv)->toContain('impressions')
        ->and($csv)->toContain('Roast House')
        // Excel reads a bare UTF-8 CSV as the system codepage; the BOM stops
        // a non-ASCII brand name being mangled.
        ->and(str_starts_with($csv, "\xEF\xBB\xBF"))->toBeTrue();
});

it('refuses an export without the permission', function (): void {
    // Designer has media and posts, not reporting.
    $member = memberWithRole($this->tenant, 'Designer');

    $this->actingAs($member)
        ->get(route('agency.reports.export', ['brand' => $this->brand->getKey()]))
        ->assertForbidden();
});

it('cannot export another agency brand', function (): void {
    [$rival] = provisionTenant('Rival Agency');
    actingForTenant($rival);
    $foreign = Customer::factory()->create(['tenant_id' => $rival->getKey()]);

    actingForTenant($this->tenant);

    $this->actingAs($this->owner)
        ->get(route('agency.reports.export', ['brand' => $foreign->getKey()]))
        ->assertNotFound();
});

// --------------------------------------------------------------- share links

it('mints a link and shows the token exactly once', function (): void {
    /*
     | The plaintext is never stored, so the flash is the only moment it
     | exists. What IS stored is the hash -- a database read must not yield a
     | working link.
     */
    $this->actingAs($this->owner)
        ->from(route('agency.analytics.index'))
        ->post(route('agency.reports.share'), [
            'brand' => $this->brand->getKey(),
            'days' => 30,
            'expires_in_days' => 14,
        ])
        ->assertSessionHas('share_url');

    $share = ReportShare::query()->sole();

    expect(strlen($share->token_hash))->toBe(64)
        ->and($share->expires_at->isFuture())->toBeTrue()
        ->and($share->isViewable())->toBeTrue();
});

it('freezes the window at creation', function (): void {
    /*
     | "Last 30 days" evaluated on view would mean a link sent in January
     | quietly shows April's numbers, and the client reads a report nobody at
     | the agency ever saw.
     */
    $this->actingAs($this->owner)
        ->post(route('agency.reports.share'), [
            'brand' => $this->brand->getKey(),
            'days' => 7,
            'expires_in_days' => 14,
        ]);

    $share = ReportShare::query()->sole();

    expect($share->window_from->diffInDays($share->window_to, absolute: true))
        ->toBeLessThan(8);
});

it('refuses an expiry beyond the maximum', function (): void {
    // A link that lives for ever is a permanent unauthenticated view of a
    // client's performance.
    $this->actingAs($this->owner)
        ->post(route('agency.reports.share'), [
            'brand' => $this->brand->getKey(),
            'expires_in_days' => 400,
        ])
        ->assertSessionHasErrors('expires_in_days');
});

// ------------------------------------------------------------- viewing a link

it('shows the report to somebody with the link and no account', function (): void {
    reportedTarget(impressions: 4321);

    $this->actingAs($this->owner)
        ->post(route('agency.reports.share'), [
            'brand' => $this->brand->getKey(),
            'days' => 30,
            'expires_in_days' => 14,
        ]);

    $url = session('share_url');

    // Signed out entirely: the whole point is a client with no account.
    auth()->guard('web')->logout();
    withoutTenantContext();

    $this->get($url)
        ->assertOk()
        ->assertSee('Roast House')
        ->assertSee('4,321');
});

it('is a 404 once revoked', function (): void {
    $share = ReportShare::factory()->revoked()->create([
        'tenant_id' => $this->tenant->getKey(),
        'customer_id' => $this->brand->getKey(),
    ]);

    // The token is not recoverable from the row, so mint a known one.
    ['token' => $token, 'hash' => $hash] = ReportShare::newToken();
    $share->forceFill(['token_hash' => $hash])->save();

    withoutTenantContext();

    $this->get(route('reports.shared', $token))->assertNotFound();
});

it('is a 404 once expired', function (): void {
    $share = ReportShare::factory()->expired()->create([
        'tenant_id' => $this->tenant->getKey(),
        'customer_id' => $this->brand->getKey(),
    ]);

    ['token' => $token, 'hash' => $hash] = ReportShare::newToken();
    $share->forceFill(['token_hash' => $hash])->save();

    withoutTenantContext();

    $this->get(route('reports.shared', $token))->assertNotFound();
});

it('is a 404 for a token nobody issued', function (): void {
    /*
     | The same 404 as expired and revoked. Distinguishing them would confirm
     | to a stranger that a report for some client exists.
     */
    withoutTenantContext();

    $this->get(route('reports.shared', 'not-a-real-token'))->assertNotFound();
});

it('shows only the brand and window the link names', function (): void {
    /*
     | Nothing is taken from the request. A leaked link cannot be edited into a
     | wider one, which is the whole reason the window lives in the row.
     */
    $other = Customer::factory()->create([
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'Somebody Elses Brand',
    ]);
    reportedTarget($other, impressions: 98765);
    reportedTarget(impressions: 111);

    $this->actingAs($this->owner)
        ->post(route('agency.reports.share'), [
            'brand' => $this->brand->getKey(),
            'days' => 30,
            'expires_in_days' => 14,
        ]);

    $url = session('share_url');

    auth()->guard('web')->logout();
    withoutTenantContext();

    $this->get($url)
        ->assertOk()
        ->assertSee('Roast House')
        ->assertDontSee('Somebody Elses Brand')
        ->assertDontSee('98,765');
});

it('counts views without keeping an access log', function (): void {
    $share = ReportShare::factory()->create([
        'tenant_id' => $this->tenant->getKey(),
        'customer_id' => $this->brand->getKey(),
    ]);

    ['token' => $token, 'hash' => $hash] = ReportShare::newToken();
    $share->forceFill(['token_hash' => $hash])->save();

    withoutTenantContext();

    $this->get(route('reports.shared', $token))->assertOk();

    expect($share->fresh()->view_count)->toBe(1)
        ->and($share->fresh()->last_viewed_at)->not->toBeNull();
});

it('lets an agency revoke a link', function (): void {
    $share = ReportShare::factory()->create([
        'tenant_id' => $this->tenant->getKey(),
        'customer_id' => $this->brand->getKey(),
    ]);

    $this->actingAs($this->owner)
        ->from(route('agency.analytics.index'))
        ->delete(route('agency.reports.share.revoke', $share))
        ->assertRedirect(route('agency.analytics.index'));

    expect($share->fresh()->isRevoked())->toBeTrue()
        ->and($share->fresh()->isViewable())->toBeFalse();
});

it('cannot revoke another agency link', function (): void {
    [$rival] = provisionTenant('Rival Agency');
    actingForTenant($rival);
    $foreignBrand = Customer::factory()->create(['tenant_id' => $rival->getKey()]);
    $foreign = ReportShare::factory()->create([
        'tenant_id' => $rival->getKey(),
        'customer_id' => $foreignBrand->getKey(),
    ]);

    actingForTenant($this->tenant);

    $this->actingAs($this->owner)
        ->delete(route('agency.reports.share.revoke', $foreign))
        ->assertNotFound();

    expect($foreign->fresh()->isRevoked())->toBeFalse();
});

it('never counts a post twice in an export', function (): void {
    // The same rule the dashboard follows: analytics are re-polled as a post
    // matures, and summing every collection is how figures stop reconciling.
    $target = reportedTarget(impressions: 100);

    /*
     | A minute EARLIER, not later. The window ends now, so a collection
     | stamped in the future is correctly outside it -- which is what the first
     | version of this test got wrong: it proved the window filter works rather
     | than the de-duplication it claims to test.
     */
    app(RecordPostMetricsService::class)
        ->record($target, ['impressions' => 900], null, now()->subMinute());

    $csv = $this->actingAs($this->owner)
        ->get(route('agency.reports.export', ['brand' => $this->brand->getKey(), 'days' => 30]))
        ->streamedContent();

    expect(substr_count($csv, "\n"))->toBe(2)   // header plus one row
        ->and($csv)->toContain('900')
        ->and(PostMetric::query()->count())->toBe(2);
});
