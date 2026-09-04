<?php

declare(strict_types=1);

use App\Domain\Audit\Enums\LoginEvent;
use App\Domain\Audit\Models\LoginHistory;
use App\Domain\Identity\Models\CustomerPortalUser;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Services\ProvisionTenantService;

/*
 | Showing a user their own account activity.
 |
 | login_histories has been written on every login, logout, failure, lockout
 | and password reset since Phase 1, and read by nothing. The migration even
 | carries an index on (authenticatable_type, authenticatable_id, created_at)
 | built for exactly this query, which no code ran.
 |
 | A security log nobody can see does not protect anybody. Noticing "signed in
 | from a country I have never visited" is the entire point, and only the
 | account holder can notice it.
 */

beforeEach(function (): void {
    seedPermissions();

    $this->owner = User::factory()->create();
    $this->tenant = app(ProvisionTenantService::class)->execute($this->owner, 'Bright Digital');
    $this->owner = $this->owner->fresh();

    actingForTenant($this->tenant);
});

/** An event in somebody's history. */
function recordEvent(
    LoginEvent $event,
    ?object $actor = null,
    array $overrides = [],
): LoginHistory {
    $actor ??= test()->owner;

    return LoginHistory::query()->forceCreate(array_merge([
        'tenant_id' => test()->tenant->getKey(),
        'authenticatable_type' => $actor::class,
        'authenticatable_id' => $actor->getAuthIdentifier(),
        'event' => $event->value,
        'ip' => '203.0.113.7',
        'device' => 'desktop',
        'platform' => 'Windows',
        'browser' => 'Firefox',
        'created_at' => now(),
    ], $overrides));
}

it('shows the user their own recent activity', function (): void {
    recordEvent(LoginEvent::Login);
    recordEvent(LoginEvent::Failed, overrides: ['ip' => '198.51.100.9']);

    asAgencyUser($this->owner)
        ->get(route('agency.sessions.index'))
        ->assertOk()
        ->assertSee('203.0.113.7')
        ->assertSee('198.51.100.9');
});

it('does not show one user the activity of another', function (): void {
    /*
     | Identity is the authorisation here, exactly as it is for the session
     | list above it. Two colleagues in one agency must not read each other's
     | sign-in locations.
     */
    $colleague = memberWithRole($this->tenant, 'Manager');

    recordEvent(LoginEvent::Login, $colleague, ['ip' => '198.51.100.44']);

    asAgencyUser($this->owner)
        ->get(route('agency.sessions.index'))
        ->assertOk()
        ->assertDontSee('198.51.100.44');
});

it('does not confuse an agency user with a portal user of the same id', function (): void {
    /*
     | Ids overlap between `users` and `customer_portal_users`. The sessions
     | list above already had to be fixed for this with a guard column; the
     | morph TYPE is what does the same job here, and matching on id alone
     | would show a staff member a client's sign-in history.
     */
    $portalUser = CustomerPortalUser::factory()->create([
        'tenant_id' => $this->tenant->getKey(),
        'id' => $this->owner->getKey(),
    ]);

    recordEvent(LoginEvent::Login, $portalUser, ['ip' => '198.51.100.77']);

    asAgencyUser($this->owner)
        ->get(route('agency.sessions.index'))
        ->assertOk()
        ->assertDontSee('198.51.100.77');
});

it('flags the events that matter for security', function (): void {
    // isSecurityRelevant() has had no caller since it was written. It decides
    // which rows are highlighted rather than which are shown -- a successful
    // sign-in from somewhere unexpected is the thing a user recognises.
    expect(LoginEvent::Failed->isSecurityRelevant())->toBeTrue()
        ->and(LoginEvent::Locked->isSecurityRelevant())->toBeTrue()
        ->and(LoginEvent::PasswordReset->isSecurityRelevant())->toBeTrue()
        ->and(LoginEvent::Login->isSecurityRelevant())->toBeFalse();

    recordEvent(LoginEvent::Locked);

    asAgencyUser($this->owner)
        ->get(route('agency.sessions.index'))
        ->assertOk()
        ->assertSee('Account locked');
});

it('shows the newest activity first and caps the list', function (): void {
    /*
     | Bounded. A busy account accumulates thousands of these rows, and an
     | unbounded query on a screen somebody opens because they are worried is
     | the wrong moment to be slow.
     |
     | The browser name carries the marker rather than the IP: "203.0.113.1" is
     | a prefix of "203.0.113.19", so an absence assertion on it would pass or
     | fail for the wrong reason.
     */
    foreach (range(1, 30) as $i) {
        recordEvent(LoginEvent::Login, overrides: [
            'browser' => $i === 1 ? 'Ancientfox' : ($i === 30 ? 'Newestfox' : 'Firefox'),
            'created_at' => now()->subMinutes(31 - $i),
        ]);
    }

    asAgencyUser($this->owner)
        ->get(route('agency.sessions.index'))
        ->assertOk()
        ->assertSee('Newestfox')
        ->assertDontSee('Ancientfox');
});

it('is not reachable without signing in', function (): void {
    $this->get(route('agency.sessions.index'))->assertRedirect();
});
