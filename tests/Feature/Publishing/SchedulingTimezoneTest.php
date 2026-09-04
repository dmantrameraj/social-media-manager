<?php

declare(strict_types=1);

use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Publishing\Models\Post;
use App\Domain\Publishing\Models\PostTarget;
use App\Domain\Publishing\Services\ReschedulePostService;
use App\Domain\Social\Models\SocialAccount;
use App\Domain\Tenancy\Services\ProvisionTenantService;
use Illuminate\Support\Carbon;

/*
 | Scheduling across timezones, including a DST boundary.
 |
 | The roadmap criterion asks for three zones and a boundary. The reason is not
 | thoroughness for its own sake: an agency in London scheduling for a client in
 | New York is the ordinary case, and the failure mode -- posts going out an
 | hour early, twice a year, for a fortnight -- is one nobody reports as a bug.
 | They just think you are unreliable.
 |
 | Two rules are being tested, and they are different:
 |
 |   1. A wall-clock time entered for a brand means that time IN THE BRAND'S
 |      ZONE, and is stored as the UTC instant it denotes.
 |   2. Moving a post to another DAY keeps its wall-clock time, even when the
 |      offset changes underneath it.
 |
 | Rule 2 is why the stored instant is not simply shifted by a fixed number of
 | seconds. 09:00 is 09:00 on both sides of a DST transition; the UTC instant
 | behind it is not the same distance away.
 */

beforeEach(function (): void {
    seedPermissions();

    // Before the 2026 transitions in every zone under test.
    $this->travelTo(Carbon::parse('2026-02-01 12:00:00', 'UTC'));

    $this->owner = User::factory()->create();
    $this->tenant = app(ProvisionTenantService::class)->execute($this->owner, 'Bright Digital');
    $this->owner = $this->owner->fresh();

    actingForTenant($this->tenant);
});

/** A brand in a given zone, with an account and a post scheduled locally. */
function brandInZone(string $zone, string $localTime): array
{
    $brand = Customer::factory()->create([
        'tenant_id' => test()->tenant->getKey(),
        'timezone' => $zone,
    ]);

    $account = SocialAccount::factory()->forCustomer($brand)->create();

    $post = Post::factory()
        ->forCustomer($brand)
        ->scheduledFor(Carbon::parse($localTime, $zone)->utc())
        ->create();

    PostTarget::factory()
        ->targeting($post, $account)
        ->create(['scheduled_at' => $post->scheduled_at]);

    return [$brand, $post];
}

// ------------------------------------------------------- three zones, stored

it('stores a local time as the instant it denotes', function (string $zone, string $local, string $utc): void {
    [, $post] = brandInZone($zone, $local);

    expect($post->scheduled_at->utc()->format('Y-m-d H:i'))->toBe($utc)
        // And the target agrees, which is what the dispatcher will read.
        ->and($post->targets()->sole()->scheduled_at->utc()->format('Y-m-d H:i'))->toBe($utc);
})->with([
    // India does not observe DST at all: a flat +05:30 the year round.
    'Asia/Kolkata' => ['Asia/Kolkata', '2026-03-10 09:00', '2026-03-10 03:30'],
    // London is still on GMT on 10 March; BST does not begin until the 29th.
    'Europe/London' => ['Europe/London', '2026-03-10 09:00', '2026-03-10 09:00'],
    // New York is already on EDT on 10 March; it moved on the 8th.
    'America/New_York' => ['America/New_York', '2026-03-10 09:00', '2026-03-10 13:00'],
]);

it('reads the same wall clock back in the brand zone', function (string $zone): void {
    [$brand, $post] = brandInZone($zone, '2026-04-15 17:45');

    // The round trip is the promise: what was entered is what is displayed,
    // whatever the offset in between.
    expect($post->scheduled_at->setTimezone($brand->timezone)->format('Y-m-d H:i'))
        ->toBe('2026-04-15 17:45');
})->with(['Asia/Kolkata', 'Europe/London', 'America/New_York']);

// ------------------------------------------------------------ the DST border

it('keeps the wall clock when a post is moved across a spring transition', function (): void {
    /*
     | US DST begins on 8 March 2026. A post at 09:00 on the 1st is 14:00 UTC
     | (EST, -5); moved to the 15th it must still be 09:00 local -- which is
     | 13:00 UTC (EDT, -4).
     |
     | A reschedule that added fourteen days to the stored instant would put it
     | at 14:00 UTC, and the post would go out at 10:00 local. An hour late,
     | for the eight months of the year the client is most active.
     */
    [$brand, $post] = brandInZone('America/New_York', '2026-03-01 09:00');

    expect($post->scheduled_at->utc()->format('H:i'))->toBe('14:00');

    $scheduler = app(ReschedulePostService::class);
    $scheduler->execute($post, $scheduler->resolve($post, '2026-03-15 09:00'), $this->owner);

    $post->refresh();

    expect($post->scheduled_at->setTimezone($brand->timezone)->format('Y-m-d H:i'))
        ->toBe('2026-03-15 09:00')
        ->and($post->scheduled_at->utc()->format('H:i'))->toBe('13:00')
        // The offset moved by an hour, so the stored instant did too.
        ->and($post->targets()->sole()->scheduled_at->utc()->format('H:i'))->toBe('13:00');
});

it('keeps the wall clock when a post is moved across an autumn transition', function (): void {
    /*
     | The other direction, in a different zone, because the two are not
     | symmetric in the tz database and a fix for one can miss the other.
     | British Summer Time ends on 25 October 2026.
     */
    $this->travelTo(Carbon::parse('2026-10-01 12:00:00', 'UTC'));

    [$brand, $post] = brandInZone('Europe/London', '2026-10-20 08:00');

    expect($post->scheduled_at->utc()->format('H:i'))->toBe('07:00'); // BST, +1

    $scheduler = app(ReschedulePostService::class);
    $scheduler->execute($post, $scheduler->resolve($post, '2026-11-03 08:00'), $this->owner);

    $post->refresh();

    expect($post->scheduled_at->setTimezone($brand->timezone)->format('Y-m-d H:i'))
        ->toBe('2026-11-03 08:00')
        ->and($post->scheduled_at->utc()->format('H:i'))->toBe('08:00'); // GMT
});

it('does not drag existing posts when a brand changes timezone', function (): void {
    /*
     | The post snapshots the zone it was written in. A brand that later moves
     | from London to Kolkata must not silently shift every post already on the
     | calendar by five and a half hours -- those times were promised to a
     | client.
     */
    [$brand, $post] = brandInZone('Europe/London', '2026-04-15 09:00');

    $was = $post->scheduled_at->copy();

    $brand->forceFill(['timezone' => 'Asia/Kolkata'])->save();

    expect($post->refresh()->scheduled_at->equalTo($was))->toBeTrue()
        ->and($post->timezone)->toBe('Europe/London');

    // And a later move is still interpreted in the post's own zone.
    $scheduler = app(ReschedulePostService::class);
    $scheduler->execute($post, $scheduler->resolve($post, '2026-04-20 09:00'), $this->owner);

    expect($post->refresh()->scheduled_at->setTimezone('Europe/London')->format('H:i'))
        ->toBe('09:00');
});

// ----------------------------------------------------------- what is on view

it('draws a post on the calendar day its brand would call it', function (): void {
    /*
     | scheduled_at is UTC and the grid is drawn in the brand's zone. A post at
     | 02:00 on 1 April in Kolkata is stored as 20:30 on 31 March, and grouping
     | by the UTC date would draw it in the wrong month.
     */
    [, $post] = brandInZone('Asia/Kolkata', '2026-04-01 02:00');

    expect($post->scheduled_at->utc()->toDateString())->toBe('2026-03-31');

    asAgencyUser($this->owner)
        ->get(route('agency.calendar', ['month' => '2026-04-01']))
        ->assertOk()
        ->assertSee($post->title);
});
