<?php

declare(strict_types=1);

use App\Domain\Customers\Enums\PortalRole;
use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\CustomerPortalUser;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\NotificationPreferences;
use App\Domain\Notifications\PostEventNotification;
use App\Domain\Publishing\Enums\PostStatus;
use App\Domain\Publishing\Models\Post;
use App\Domain\Publishing\Workflow\PostStatusMachine;
use App\Domain\Tenancy\Enums\MembershipStatus;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Services\ProvisionTenantService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    seedPermissions();
    Notification::fake();

    $this->owner = User::factory()->create();
    $this->tenant = app(ProvisionTenantService::class)->execute($this->owner, 'Bright Digital');
    $this->owner = $this->owner->fresh();

    actingForTenant($this->tenant);

    $this->brand = Customer::factory()->create([
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'Roast House',
    ]);

    $this->client = CustomerPortalUser::factory()->forTenant($this->tenant)->create();
    $this->brand->portalUsers()->attach($this->client->getKey(), ['role' => PortalRole::Approver->value]);
    $this->client = $this->client->fresh();

    $this->machine = app(PostStatusMachine::class);
});

/**
 * A tenant member with no brand assignment and no view-all permission.
 *
 * The Designer template deliberately lacks customers.view_all, so this person
 * belongs to the agency but can reach no brand -- which is the case that proves
 * assignment is enforced separately from membership.
 */
function memberRestrictedToNothing(Tenant $tenant): User
{
    $user = User::factory()->create();

    $user->tenants()->attach($tenant->getKey(), [
        'status' => MembershipStatus::Active->value,
        'joined_at' => now(),
    ]);

    $registrar = app(PermissionRegistrar::class);
    $previous = $registrar->getPermissionsTeamId();
    $registrar->setPermissionsTeamId($tenant->getKey());

    try {
        $user->assignRole('Designer');
    } finally {
        $registrar->setPermissionsTeamId($previous);
    }

    return $user->fresh();
}

function notifiablePost(Customer $brand, PostStatus $status, ?User $author = null): Post
{
    return Post::factory()->forCustomer($brand)->status($status)->create([
        'created_by_user_id' => $author?->getKey(),
    ]);
}

// ---------------------------------------------------------------- the loop

it('tells the client when content is sent for review', function (): void {
    // Without this the approval loop is silent: the agency sends a post and the
    // client has no idea until they happen to log in.
    $post = notifiablePost($this->brand, PostStatus::ManagerApproved, $this->owner);

    $this->machine->transition($post, PostStatus::ClientReview, $this->owner);

    Notification::assertSentTo(
        $this->client,
        PostEventNotification::class,
        fn (PostEventNotification $n): bool => $n->event === 'post.client_review',
    );
});

it('tells the agency when the client approves', function (): void {
    $post = notifiablePost($this->brand, PostStatus::ClientReview, $this->owner);

    $this->machine->transition($post, PostStatus::ClientApproved, $this->client, 'Looks great.');

    Notification::assertSentTo(
        $this->owner,
        PostEventNotification::class,
        fn (PostEventNotification $n): bool => $n->event === 'post.client_approved'
            && $n->comment === 'Looks great.',
    );
});

it('tells the agency what the client actually said when asking for changes', function (): void {
    // "They asked for changes" without the changes generates a second message
    // asking what they were.
    $post = notifiablePost($this->brand, PostStatus::ClientReview, $this->owner);

    $this->machine->transition($post, PostStatus::Draft, $this->client, 'Can we soften the headline?');

    Notification::assertSentTo(
        $this->owner,
        PostEventNotification::class,
        fn (PostEventNotification $n): bool => $n->event === 'post.changes_requested'
            && str_contains((string) $n->comment, 'soften the headline'),
    );
});

it('does not tell the agency what it just did itself', function (): void {
    // Approve and reject happen inside the agency too. The agency does not need
    // an email about its own click.
    $post = notifiablePost($this->brand, PostStatus::ClientReview, $this->owner);

    $this->machine->transition($post, PostStatus::ClientApproved, $this->owner);

    Notification::assertNotSentTo($this->owner, PostEventNotification::class);
});

it('says nothing at all for ordinary internal bookkeeping', function (): void {
    // A product that notifies on every status change teaches people to filter
    // it away -- including the failures.
    $post = notifiablePost($this->brand, PostStatus::Draft, $this->owner);

    $this->machine->transition($post, PostStatus::InternalReview, $this->owner);

    Notification::assertNothingSent();
});

// ------------------------------------------------------------- the boundary

it('never sends an agency event to a client', function (): void {
    /*
     | The expensive mistake this guards: an agency-audience event reaching a
     | portal user would put internal language -- "the client rejected this" --
     | in front of the client it is about.
     */
    $post = notifiablePost($this->brand, PostStatus::ClientReview, $this->owner);

    $this->machine->transition($post, PostStatus::ClientApproved, $this->client);

    Notification::assertNotSentTo($this->client, PostEventNotification::class);
});

it('never sends a client event to the agency', function (): void {
    $post = notifiablePost($this->brand, PostStatus::ManagerApproved, $this->owner);

    $this->machine->transition($post, PostStatus::ClientReview, $this->owner);

    Notification::assertNotSentTo($this->owner, PostEventNotification::class);
});

it('does not notify a team member restricted to other brands', function (): void {
    // Assignment is a boundary, not a preference.
    $designer = memberRestrictedToNothing($this->tenant);

    $post = notifiablePost($this->brand, PostStatus::ClientReview, $this->owner);
    $this->machine->transition($post, PostStatus::ClientApproved, $this->client);

    Notification::assertNotSentTo($designer, PostEventNotification::class);
});

it('does not notify a client of another brand', function (): void {
    $otherBrand = Customer::factory()->create(['tenant_id' => $this->tenant->getKey()]);
    $otherClient = CustomerPortalUser::factory()->forTenant($this->tenant)->create();
    $otherBrand->portalUsers()->attach($otherClient->getKey(), ['role' => PortalRole::Approver->value]);

    $post = notifiablePost($this->brand, PostStatus::ManagerApproved, $this->owner);
    $this->machine->transition($post, PostStatus::ClientReview, $this->owner);

    Notification::assertSentTo($this->client, PostEventNotification::class);
    Notification::assertNotSentTo($otherClient->fresh(), PostEventNotification::class);
});

it('does not notify an account that cannot sign in', function (): void {
    // A message to an account that cannot authenticate is a bounce and a
    // support ticket. UserStatus has two cases -- active and disabled -- and
    // only active may be notified.
    $this->client->forceFill(['status' => UserStatus::Disabled->value])->save();

    $post = notifiablePost($this->brand, PostStatus::ManagerApproved, $this->owner);
    $this->machine->transition($post, PostStatus::ClientReview, $this->owner);

    Notification::assertNothingSent();
});

// ------------------------------------------------------------- preferences

it('respects a stored preference', function (): void {
    DB::table('notification_preferences')->insert([
        'tenant_id' => $this->tenant->getKey(),
        'user_id' => $this->owner->getKey(),
        'event_key' => 'post.client_approved',
        'channel' => 'mail',
        'enabled' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $channels = app(NotificationPreferences::class)
        ->channelsFor($this->owner, 'post.client_approved');

    expect($channels)->not->toContain('mail')
        ->and($channels)->toContain('database');
});

it('treats a missing preference as the default, not as opted out', function (): void {
    /*
     | A user who signed up before an event existed has no row for it. Reading
     | absence as "off" would silently stop every notification the product ever
     | adds -- the failure nobody reports, because nothing arrives to complain
     | about.
     */
    expect(DB::table('notification_preferences')->where('user_id', $this->owner->getKey())->count())
        ->toBe(0);

    expect(app(NotificationPreferences::class)->channelsFor($this->owner, 'post.client_approved'))
        ->toContain('mail', 'database');
});

it('does not email every published post by default', function (): void {
    // An agency running fifty posts a day does not want fifty emails. That is
    // how a product trains people to filter its mail away.
    $channels = app(NotificationPreferences::class)->channelsFor($this->owner, 'post.published');

    expect($channels)->toContain('database')
        ->and($channels)->not->toContain('mail');
});

it('does email a publishing failure by default', function (): void {
    // The one thing an agency must hear about, because the customer notices
    // first otherwise.
    expect(app(NotificationPreferences::class)->channelsFor($this->owner, 'post.publish_failed'))
        ->toContain('mail');
});

it('refuses an unknown event rather than notifying nobody', function (): void {
    // Silence is the failure mode that never gets reported.
    expect(fn () => app(NotificationPreferences::class)->channelsFor($this->owner, 'post.nonsense'))
        ->toThrow(InvalidArgumentException::class);
});

it('declares exactly one audience for every event', function (): void {
    $preferences = app(NotificationPreferences::class);

    foreach ($preferences->eventKeys() as $event) {
        expect($preferences->audienceFor($event))->toBeIn(
            ['agency', 'client'],
            "[{$event}] has no valid audience, so its recipients are undefined.",
        );
    }
});

// ------------------------------------------------------------- resilience

it('does not roll back a transition when delivery fails', function (): void {
    /*
     | A post that moved but whose email bounced is a missing email. A
     | transition rolled back because a mail server was down is a lost decision.
     */
    Notification::shouldReceive('send')->andThrow(new RuntimeException('SMTP is down'));

    $post = notifiablePost($this->brand, PostStatus::ClientReview, $this->owner);

    $this->machine->transition($post, PostStatus::ClientApproved, $this->client);

    expect($post->fresh()->status)->toBe(PostStatus::ClientApproved);
});

it('snapshots the title instead of carrying the model', function (): void {
    // A queued notification is serialized; re-querying at send time would let a
    // post edited or deleted in between produce a message describing something
    // that never happened.
    $post = notifiablePost($this->brand, PostStatus::ClientReview, $this->owner);
    $post->forceFill(['title' => 'Original title'])->save();

    $notification = PostEventNotification::for('post.client_approved', $post->fresh());

    expect($notification->postTitle)->toBe('Original title')
        ->and($notification->brandName)->toBe('Roast House');
});
