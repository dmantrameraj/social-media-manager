<?php

declare(strict_types=1);

use App\Domain\Identity\Models\CustomerPortalUser;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\PortalPasswordResetNotification;
use App\Domain\Tenancy\Services\ProvisionTenantService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

beforeEach(function (): void {
    seedPermissions();
    Notification::fake();

    $this->owner = User::factory()->create();
    $this->tenant = app(ProvisionTenantService::class)->execute($this->owner, 'Bright Digital');

    actingForTenant($this->tenant);

    $this->client = CustomerPortalUser::factory()->forTenant($this->tenant)->create([
        'password' => bcrypt('the-old-password'),
    ]);
});

// ------------------------------------------------------------------ request

it('sends a reset link', function (): void {
    // Before this, a client who forgot their password had to ask the agency to
    // re-invite them.
    $this->post(route('portal.password.email'), ['email' => $this->client->email])
        ->assertRedirect();

    Notification::assertSentTo($this->client, PortalPasswordResetNotification::class);
});

it('links to the PORTAL reset form, not the agency one', function (): void {
    /*
     | Laravel's own ResetPassword notification builds route('password.reset'),
     | which is the agency form -- a different broker and a different guard. A
     | client following it would be told their token is invalid with no way to
     | tell why.
     */
    $this->post(route('portal.password.email'), ['email' => $this->client->email]);

    $notification = Notification::sent($this->client, PortalPasswordResetNotification::class)->first();

    $url = (string) $notification->toMail($this->client)->actionUrl;

    expect($url)->toContain('/portal/reset-password/')
        ->and($url)->toContain($notification->token)
        // And the token actually works against the portal form.
        ->and($url)->toStartWith(route('portal.password.reset', ['token' => $notification->token]));
});

it('answers the same way for an address that has no account', function (): void {
    // Otherwise the form becomes a way to ask whether a given person is a
    // client of this agency.
    $known = $this->post(route('portal.password.email'), ['email' => $this->client->email]);
    $unknown = $this->post(route('portal.password.email'), ['email' => 'nobody@example.com']);

    expect($known->getSession()->get('status'))
        ->toBe($unknown->getSession()->get('status'));

    Notification::assertSentTimes(PortalPasswordResetNotification::class, 1);
});

// -------------------------------------------------------------------- reset

it('resets the password and lets the client sign in', function (): void {
    $token = Password::broker('customers')->createToken($this->client);

    $this->post(route('portal.password.update'), [
        'token' => $token,
        'email' => $this->client->email,
        'password' => 'a-much-better-password-42',
        'password_confirmation' => 'a-much-better-password-42',
    ])->assertRedirect(route('portal.login'));

    $this->post(route('portal.login.store'), [
        'email' => $this->client->email,
        'password' => 'a-much-better-password-42',
    ])->assertRedirect(route('portal.dashboard', absolute: false));

    expect(auth('customer')->id())->toBe($this->client->getKey());
});

it('rotates the remember token so an old session cannot survive', function (): void {
    // The usual reason someone resets is that they think somebody else has
    // access, so a remember cookie held elsewhere must stop working.
    $before = $this->client->remember_token;

    $this->post(route('portal.password.update'), [
        'token' => Password::broker('customers')->createToken($this->client),
        'email' => $this->client->email,
        'password' => 'a-much-better-password-42',
        'password_confirmation' => 'a-much-better-password-42',
    ]);

    expect($this->client->fresh()->remember_token)->not->toBe($before);
});

it('refuses a token that was issued for someone else', function (): void {
    $other = CustomerPortalUser::factory()->forTenant($this->tenant)->create();
    $token = Password::broker('customers')->createToken($other);

    $this->post(route('portal.password.update'), [
        'token' => $token,
        'email' => $this->client->email,
        'password' => 'a-much-better-password-42',
        'password_confirmation' => 'a-much-better-password-42',
    ])->assertSessionHasErrors('email');

    expect(password_verify('a-much-better-password-42', $this->client->fresh()->password))
        ->toBeFalse();
});

it('refuses a made-up token', function (): void {
    $this->post(route('portal.password.update'), [
        'token' => 'not-a-real-token',
        'email' => $this->client->email,
        'password' => 'a-much-better-password-42',
        'password_confirmation' => 'a-much-better-password-42',
    ])->assertSessionHasErrors('email');
});

it('refuses a token that has already been used', function (): void {
    $token = Password::broker('customers')->createToken($this->client);

    $payload = [
        'token' => $token,
        'email' => $this->client->email,
        'password' => 'a-much-better-password-42',
        'password_confirmation' => 'a-much-better-password-42',
    ];

    $this->post(route('portal.password.update'), $payload)->assertRedirect();
    $this->post(route('portal.password.update'), $payload)->assertSessionHasErrors('email');
});

it('requires the confirmation to match', function (): void {
    $this->post(route('portal.password.update'), [
        'token' => Password::broker('customers')->createToken($this->client),
        'email' => $this->client->email,
        'password' => 'a-much-better-password-42',
        'password_confirmation' => 'something-else-entirely',
    ])->assertSessionHasErrors('password');
});

// ------------------------------------------------------------------ records

it('records the reset in login history', function (): void {
    $this->post(route('portal.password.update'), [
        'token' => Password::broker('customers')->createToken($this->client),
        'email' => $this->client->email,
        'password' => 'a-much-better-password-42',
        'password_confirmation' => 'a-much-better-password-42',
    ]);

    expect(DB::table('login_histories')
        ->where('authenticatable_id', $this->client->getKey())
        ->where('event', 'password_reset')
        ->exists())->toBeTrue();
});

// ------------------------------------------------------------------- screens

it('renders the request and reset forms', function (): void {
    $this->get(route('portal.password.request'))->assertOk()->assertSee('Reset your password');

    $this->get(route('portal.password.reset', ['token' => 'abc', 'email' => $this->client->email]))
        ->assertOk()
        ->assertSee('Choose a new password');
});

it('offers the reset link from the sign-in page', function (): void {
    $this->get(route('portal.login'))->assertOk()->assertSee('Forgot your password?');
});
