<?php

declare(strict_types=1);

use App\Domain\Customers\Enums\PortalRole;
use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\CustomerPortalUser;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\NotificationPreferences;
use App\Domain\Notifications\PostEventNotification;
use App\Domain\Publishing\Enums\PostStatus;
use App\Domain\Publishing\Models\Post;
use App\Domain\Publishing\Workflow\PostStatusMachine;
use App\Domain\Tenancy\Services\ProvisionTenantService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    seedPermissions();

    $this->owner = User::factory()->create();
    $this->tenant = app(ProvisionTenantService::class)->execute($this->owner, 'Bright Digital');
    $this->owner = $this->owner->fresh();

    actingForTenant($this->tenant);

    $this->brand = Customer::factory()->create(['tenant_id' => $this->tenant->getKey()]);
});

function asSettingsUser(User $user)
{
    return test()->actingAs($user, 'web')->withSession([
        config('tenancy.resolution.session_key', 'tenant_id') => test()->tenant->getKey(),
    ]);
}

// ------------------------------------------------------------------- screen

it('shows the current settings, defaults included', function (): void {
    asSettingsUser($this->owner)
        ->get(route('agency.notifications.settings'))
        ->assertOk()
        ->assertSee('A client approved a post')
        ->assertSee('A post failed to publish');
});

it('does not offer switches for messages the user does not receive', function (): void {
    // A client event listed here would be a switch that does nothing, which is
    // worse than an absent one: people believe switches.
    asSettingsUser($this->owner)
        ->get(route('agency.notifications.settings'))
        ->assertOk()
        ->assertDontSee('Content is ready for your review');
});

// -------------------------------------------------------------------- saving

it('turns an email off and keeps it off', function (): void {
    asSettingsUser($this->owner)
        ->put(route('agency.notifications.settings.update'), [
            'prefs' => [
                'post.client_approved' => ['database' => '1'],
            ],
        ])
        ->assertRedirect();

    $channels = app(NotificationPreferences::class)
        ->channelsFor($this->owner->fresh(), 'post.client_approved');

    expect($channels)->toContain('database')
        ->and($channels)->not->toContain('mail');
});

it('records every combination, not only the ticked ones', function (): void {
    /*
     | An unchecked box submits nothing, and absence in the PAYLOAD means off --
     | the opposite of absence in the DATABASE, which means "use the default".
     |
     | Writing every combination is what stops a later change to a catalogue
     | default silently overriding a choice somebody made.
     */
    asSettingsUser($this->owner)
        ->put(route('agency.notifications.settings.update'), [
            'prefs' => ['post.client_approved' => ['mail' => '1', 'database' => '1']],
        ]);

    $agencyEvents = collect(app(NotificationPreferences::class)->eventKeys())
        ->reject(fn (string $event): bool => app(NotificationPreferences::class)->isClientEvent($event));

    $rows = DB::table('notification_preferences')->where('user_id', $this->owner->getKey())->count();

    expect($rows)->toBe($agencyEvents->count() * count((array) config('notifications.channels')));
});

it('does not silently re-enable something the user turned off', function (): void {
    asSettingsUser($this->owner)
        ->put(route('agency.notifications.settings.update'), ['prefs' => []]);

    // Every switch off. A catalogue default of "mail on" must not win.
    expect(app(NotificationPreferences::class)->channelsFor($this->owner->fresh(), 'post.client_approved'))
        ->toBe([]);
});

it('never writes a preference for a client event', function (): void {
    asSettingsUser($this->owner)
        ->put(route('agency.notifications.settings.update'), [
            'prefs' => ['post.client_review' => ['mail' => '1']],
        ]);

    expect(DB::table('notification_preferences')
        ->where('user_id', $this->owner->getKey())
        ->where('event_key', 'post.client_review')
        ->exists())->toBeFalse();
});

// -------------------------------------------------------------- end to end

it('actually stops the email it was told to stop', function (): void {
    // The point of the screen: a setting that is honoured only in a unit test
    // is not a setting.
    Notification::fake();

    asSettingsUser($this->owner)
        ->put(route('agency.notifications.settings.update'), [
            'prefs' => ['post.client_approved' => ['database' => '1']],
        ]);

    $client = CustomerPortalUser::factory()
        ->forTenant($this->tenant)->create();
    $this->brand->portalUsers()->attach($client->getKey(), [
        'role' => PortalRole::Approver->value,
    ]);

    $post = Post::factory()->forCustomer($this->brand)
        ->status(PostStatus::ClientReview)
        ->create(['created_by_user_id' => $this->owner->getKey()]);

    app(PostStatusMachine::class)->transition(
        $post,
        PostStatus::ClientApproved,
        $client->fresh(),
    );

    Notification::assertSentTo(
        $this->owner,
        PostEventNotification::class,
        function ($notification, array $channels): bool {
            return in_array('database', $channels, true)
                && ! in_array('mail', $channels, true);
        },
    );
});

// ------------------------------------------------------------------ scoping

it('changes only the signed-in user is settings', function (): void {
    $colleague = User::factory()->create();

    asSettingsUser($this->owner)
        ->put(route('agency.notifications.settings.update'), ['prefs' => []]);

    expect(DB::table('notification_preferences')->where('user_id', $colleague->getKey())->count())
        ->toBe(0);

    // The colleague still gets the defaults.
    expect(app(NotificationPreferences::class)->channelsFor($colleague, 'post.client_approved'))
        ->toContain('mail');
});

it('keeps the settings screen behind authentication', function (): void {
    $this->get(route('agency.notifications.settings'))->assertRedirect(route('login'));
});
