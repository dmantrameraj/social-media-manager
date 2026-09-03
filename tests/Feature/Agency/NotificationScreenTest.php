<?php

declare(strict_types=1);

use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\PostEventNotification;
use App\Domain\Publishing\Models\Post;
use App\Domain\Tenancy\Services\ProvisionTenantService;

beforeEach(function (): void {
    seedPermissions();

    $this->owner = User::factory()->create();
    $this->tenant = app(ProvisionTenantService::class)->execute($this->owner, 'Bright Digital');
    $this->owner = $this->owner->fresh();

    actingForTenant($this->tenant);

    $this->brand = Customer::factory()->create([
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'Roast House',
    ]);

    $this->post = Post::factory()->forCustomer($this->brand)->create(['title' => 'Autumn launch']);
});

function asViewer(User $user)
{
    return test()->actingAs($user, 'web')->withSession([
        config('tenancy.resolution.session_key', 'tenant_id') => test()->tenant->getKey(),
    ]);
}

/** Deliver a real notification through the database channel. */
function notify(User $user, Post $post, string $event = 'post.client_approved', ?string $comment = null): void
{
    $user->notify(PostEventNotification::for($event, $post->load('customer'), $comment));
}

// ------------------------------------------------------------------- listing

it('lists a delivered notification', function (): void {
    notify($this->owner, $this->post);

    asViewer($this->owner)
        ->get(route('agency.notifications.index'))
        ->assertOk()
        ->assertSee('Autumn launch')
        ->assertSee('Roast House');
});

it('says something useful when there is nothing', function (): void {
    asViewer($this->owner)
        ->get(route('agency.notifications.index'))
        ->assertOk()
        ->assertSee('No notifications yet');
});

it('shows what the client actually said', function (): void {
    // "They asked for changes" without the changes generates a second message
    // asking what they were.
    notify($this->owner, $this->post, 'post.changes_requested', 'Can we soften the headline?');

    asViewer($this->owner)
        ->get(route('agency.notifications.index'))
        ->assertOk()
        ->assertSee('soften the headline', false);
});

it('filters to unread', function (): void {
    notify($this->owner, $this->post);
    notify($this->owner, $this->post, 'post.client_rejected');

    $this->owner->unreadNotifications->first()->markAsRead();

    $response = asViewer($this->owner)
        ->get(route('agency.notifications.index', ['show' => 'unread']))
        ->assertOk();

    expect(substr_count($response->getContent(), 'Autumn launch'))->toBe(1);
});

// -------------------------------------------------------------------- unread

it('shows an unread count in the navigation', function (): void {
    notify($this->owner, $this->post);
    notify($this->owner, $this->post, 'post.client_rejected');

    asViewer($this->owner)
        ->get(route('agency.dashboard'))
        ->assertOk()
        ->assertSee('Notifications')
        ->assertSee('unread');
});

it('shows no badge when everything is read', function (): void {
    $body = asViewer($this->owner)->get(route('agency.dashboard'))->assertOk()->getContent();

    // The word "Notifications" is the nav link; "unread" only appears with the
    // badge, so its absence is the assertion.
    expect(str_contains($body, 'Notifications'))->toBeTrue()
        ->and(str_contains($body, 'unread'))->toBeFalse();
});

it('marks one read and opens what it points at', function (): void {
    // Read-and-open is one action because it is one intention: a notification
    // still unread after you followed it is noise.
    notify($this->owner, $this->post);
    $notification = $this->owner->unreadNotifications->first();

    asViewer($this->owner)
        ->post(route('agency.notifications.read', $notification->id))
        ->assertRedirect(route('agency.posts.show', $this->post));

    expect($this->owner->fresh()->unreadNotifications()->count())->toBe(0);
});

it('marks everything read at once', function (): void {
    notify($this->owner, $this->post);
    notify($this->owner, $this->post, 'post.client_rejected');

    asViewer($this->owner)
        ->post(route('agency.notifications.read-all'))
        ->assertRedirect(route('agency.notifications.index'));

    expect($this->owner->fresh()->unreadNotifications()->count())->toBe(0);
});

// ------------------------------------------------------------------ isolation

it('never shows one user another user is notifications', function (): void {
    /*
     | Scoped through the relation rather than by a where-clause someone has to
     | remember: a notification belonging to someone else is simply not in the
     | set, so there is no ownership check that can be forgotten.
     */
    $colleague = User::factory()->create();

    notify($colleague, $this->post);

    asViewer($this->owner)
        ->get(route('agency.notifications.index'))
        ->assertOk()
        ->assertDontSee('Autumn launch');
});

it('refuses to mark another user is notification read', function (): void {
    $colleague = User::factory()->create();
    notify($colleague, $this->post);

    $foreign = $colleague->unreadNotifications->first();

    asViewer($this->owner)
        ->post(route('agency.notifications.read', $foreign->id))
        ->assertNotFound();

    expect($colleague->fresh()->unreadNotifications()->count())->toBe(1);
});

it('keeps the screen behind authentication and tenancy', function (): void {
    $this->get(route('agency.notifications.index'))->assertRedirect(route('login'));
});

// ------------------------------------------------------------------ payload

it('still renders after the post it describes is gone', function (): void {
    /*
     | The row stores flat scalars snapshotted at dispatch, not a serialized
     | model. A notification read months later must not blank out — or worse,
     | error — because the post was deleted.
     */
    notify($this->owner, $this->post);

    $this->post->delete();

    asViewer($this->owner)
        ->get(route('agency.notifications.index'))
        ->assertOk()
        ->assertSee('Autumn launch');
});
