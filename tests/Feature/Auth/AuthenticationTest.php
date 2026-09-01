<?php

declare(strict_types=1);

use App\Domain\Audit\Enums\LoginEvent;
use App\Domain\Audit\Models\LoginHistory;
use App\Domain\Identity\Models\CustomerPortalUser;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Hash;

beforeEach(fn () => seedPermissions());

it('signs a user in with correct credentials', function (): void {
    $user = User::factory()->create([
        'email' => 'meraj@example.com',
        'password' => Hash::make('correct-horse-battery-99'),
    ]);

    $response = $this->post(route('login.store'), [
        'email' => 'meraj@example.com',
        'password' => 'correct-horse-battery-99',
    ]);

    $response->assertRedirect();
    $this->assertAuthenticatedAs($user, 'web');
});

it('rejects a wrong password', function (): void {
    User::factory()->create([
        'email' => 'meraj@example.com',
        'password' => Hash::make('correct-horse-battery-99'),
    ]);

    $this->post(route('login.store'), [
        'email' => 'meraj@example.com',
        'password' => 'wrong-password-entirely',
    ])->assertSessionHasErrors();

    $this->assertGuest('web');
});

it('refuses a disabled account even with the correct password', function (): void {
    User::factory()->disabled()->create([
        'email' => 'disabled@example.com',
        'password' => Hash::make('correct-horse-battery-99'),
    ]);

    $this->post(route('login.store'), [
        'email' => 'disabled@example.com',
        'password' => 'correct-horse-battery-99',
    ])->assertSessionHasErrors();

    $this->assertGuest('web');
});

it('records a successful login without storing the password', function (): void {
    $user = User::factory()->create([
        'email' => 'meraj@example.com',
        'password' => Hash::make('correct-horse-battery-99'),
    ]);

    $this->post(route('login.store'), [
        'email' => 'meraj@example.com',
        'password' => 'correct-horse-battery-99',
    ]);

    $entry = LoginHistory::query()->latest('id')->firstOrFail();

    expect($entry->event)->toBe(LoginEvent::Login)
        ->and($entry->authenticatable_id)->toBe($user->getKey())
        ->and($entry->authenticatable_type)->toBe(User::class);

    // The whole row must not contain the password in any field.
    expect(json_encode($entry->toArray()))
        ->not->toContain('correct-horse-battery-99');
});

it('records a failed login without storing the attempted password', function (): void {
    User::factory()->create([
        'email' => 'meraj@example.com',
        'password' => Hash::make('correct-horse-battery-99'),
    ]);

    $this->post(route('login.store'), [
        'email' => 'meraj@example.com',
        'password' => 'the-attackers-guess',
    ]);

    $entry = LoginHistory::query()->latest('id')->firstOrFail();

    expect($entry->event)->toBe(LoginEvent::Failed)
        ->and(json_encode($entry->toArray()))->not->toContain('the-attackers-guess');
});

it('throttles repeated failed logins with a hard 429', function (): void {
    User::factory()->create([
        'email' => 'meraj@example.com',
        'password' => Hash::make('correct-horse-battery-99'),
    ]);

    $attempt = fn () => $this->post(route('login.store'), [
        'email' => 'meraj@example.com',
        'password' => 'wrong-password-entirely',
    ]);

    // The limiter allows five attempts per minute per email + ip pair. Those
    // five come back as ordinary credential errors...
    foreach (range(1, 5) as $ignored) {
        $attempt()->assertRedirect()->assertSessionHasErrors('email');
    }

    // ...and the sixth is refused outright, not as a soft validation message.
    $attempt()->assertStatus(429);
});

/*
 | NOTE: Fortify throttles via route middleware that aborts with 429 directly.
 | It does NOT dispatch Illuminate\Auth\Events\Lockout, so no `locked` row is
 | written. A lockout is therefore visible as a run of consecutive `failed`
 | events from one address rather than as a distinct event.
 |
 | RecordAuthenticationEvent still subscribes to Lockout so that any code path
 | which does dispatch it is captured. Recording the 429 itself would mean
 | wrapping Fortify's throttle middleware; deferred to Step 12, where the
 | admin security screen actually consumes this data.
 */
it('records each throttled failure as a failed event', function (): void {
    User::factory()->create([
        'email' => 'meraj@example.com',
        'password' => Hash::make('correct-horse-battery-99'),
    ]);

    foreach (range(1, 5) as $ignored) {
        $this->post(route('login.store'), [
            'email' => 'meraj@example.com',
            'password' => 'wrong-password-entirely',
        ]);
    }

    expect(LoginHistory::query()->where('event', LoginEvent::Failed->value)->count())
        ->toBe(5);
});

it('updates last_login_at on success', function (): void {
    $user = User::factory()->create([
        'email' => 'meraj@example.com',
        'password' => Hash::make('correct-horse-battery-99'),
    ]);

    expect($user->last_login_at)->toBeNull();

    $this->post(route('login.store'), [
        'email' => 'meraj@example.com',
        'password' => 'correct-horse-battery-99',
    ]);

    expect($user->fresh()->last_login_at)->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Guard separation
|--------------------------------------------------------------------------
|
| The security property that justifies a separate table and guard for portal
| users: a portal session must never resolve to a User, and vice versa.
|
*/

it('does not authenticate a portal user through the web guard', function (): void {
    $tenant = Tenant::factory()->create();

    CustomerPortalUser::factory()->forTenant($tenant)->create([
        'email' => 'client@example.com',
        'password' => Hash::make('correct-horse-battery-99'),
    ]);

    $this->post(route('login.store'), [
        'email' => 'client@example.com',
        'password' => 'correct-horse-battery-99',
    ])->assertSessionHasErrors();

    $this->assertGuest('web');
});

it('keeps the two guards independent', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    $portalUser = CustomerPortalUser::factory()->forTenant($tenant)->create();

    $this->actingAs($user, 'web');

    // Authenticated on web, still a guest on customer.
    $this->assertAuthenticatedAs($user, 'web');
    $this->assertGuest('customer');

    $this->actingAs($portalUser, 'customer');
    $this->assertAuthenticatedAs($portalUser, 'customer');
});

it('signs the user out', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user, 'web')
        ->post(route('logout'))
        ->assertRedirect();

    $this->assertGuest('web');
});
