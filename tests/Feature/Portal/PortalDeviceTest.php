<?php

declare(strict_types=1);

use App\Domain\Audit\Enums\LoginEvent;
use App\Domain\Audit\Models\LoginHistory;
use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\CustomerPortalUser;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Services\ProvisionTenantService;
use Illuminate\Support\Facades\DB;

/*
 | A client's own devices and account history.
 |
 | The agency side has had this since Phase 1 and the portal has not, which is
 | the wrong way round to leave it: a client's account is the one the agency
 | does not control. If a brand approver's password leaks, everything that
 | account can see -- unpublished campaigns, approval conversations, a month of
 | planned content -- is exposed, and the client had no way to notice or to end
 | the session.
 |
 | login_histories has recorded their sign-ins all along. Only the screen was
 | missing.
 */

beforeEach(function (): void {
    seedPermissions();

    $owner = User::factory()->create();
    $this->tenant = app(ProvisionTenantService::class)->execute($owner, 'Bright Digital');
    actingForTenant($this->tenant);

    $this->brand = Customer::factory()->create(['tenant_id' => $this->tenant->getKey()]);

    $this->client = CustomerPortalUser::factory()->create([
        'tenant_id' => $this->tenant->getKey(),
        'email' => 'approver@brand.test',
    ]);

    $this->client->customers()->attach($this->brand->getKey(), [
        'tenant_id' => $this->tenant->getKey(),
        'role' => 'approver',
    ]);
});

/** A row in the sessions table, as the session driver would write it. */
function portalSession(string $id, int $userId, string $guard = 'customer', string $agent = 'Mozilla/5.0 (Windows NT 10.0) Chrome/120'): void
{
    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $userId,
        'guard' => $guard,
        'ip_address' => '203.0.113.5',
        'user_agent' => $agent,
        'payload' => '',
        'last_activity' => now()->timestamp,
    ]);
}

it('shows a client the devices signed in to their account', function (): void {
    portalSession('their-laptop', $this->client->getKey());

    $this->actingAs($this->client, 'customer')
        ->get(route('portal.devices'))
        ->assertOk()
        ->assertSee('203.0.113.5')
        // Coarse on purpose: enough to recognise your own devices, without a
        // UA parser and the fingerprinting surface that comes with one.
        ->assertSee('Chrome on Windows');
});

it('does not show one client the devices of another', function (): void {
    $otherBrand = Customer::factory()->create(['tenant_id' => $this->tenant->getKey()]);

    $other = CustomerPortalUser::factory()->create([
        'tenant_id' => $this->tenant->getKey(),
        'email' => 'someone@else.test',
    ]);
    $other->customers()->attach($otherBrand->getKey(), [
        'tenant_id' => $this->tenant->getKey(),
        'role' => 'approver',
    ]);

    portalSession('theirs', $other->getKey(), agent: 'Mozilla/5.0 (Macintosh; Mac OS X) Firefox/120');

    $this->actingAs($this->client, 'customer')
        ->get(route('portal.devices'))
        ->assertOk()
        ->assertDontSee('Firefox on macOS');
});

it('does not confuse a client with an agency user of the same id', function (): void {
    /*
     | Ids overlap between `users` and `customer_portal_users`. The guard column
     | is what keeps the two apart, and without it a client would be shown a
     | staff member's devices -- or be able to end them.
     */
    $staff = User::factory()->create(['id' => $this->client->getKey()]);

    portalSession('staff-machine', $staff->getKey(), guard: 'web', agent: 'Mozilla/5.0 (X11; Linux) Firefox/120');

    $this->actingAs($this->client, 'customer')
        ->get(route('portal.devices'))
        ->assertOk()
        ->assertDontSee('Firefox on Linux');
});

it('lets a client end another device', function (): void {
    portalSession('old-phone', $this->client->getKey());

    $this->actingAs($this->client, 'customer')
        ->delete(route('portal.devices.destroy', 'old-phone'))
        ->assertRedirect();

    expect(DB::table('sessions')->where('id', 'old-phone')->exists())->toBeFalse();
});

it('will not let a client end an agency session', function (): void {
    $staff = User::factory()->create(['id' => $this->client->getKey()]);

    portalSession('staff-machine', $staff->getKey(), guard: 'web');

    $this->actingAs($this->client, 'customer')
        ->delete(route('portal.devices.destroy', 'staff-machine'))
        ->assertRedirect();

    // Scoped by guard as well as id, so a session id copied from elsewhere
    // matches nothing.
    expect(DB::table('sessions')->where('id', 'staff-machine')->exists())->toBeTrue();
});

it('ends every other device at once', function (): void {
    // What somebody actually wants when they think an account is compromised,
    // without working out which row is the laptop they left somewhere.
    portalSession('phone', $this->client->getKey());
    portalSession('tablet', $this->client->getKey());

    $this->actingAs($this->client, 'customer')
        ->delete(route('portal.devices.destroy-others'))
        ->assertRedirect();

    expect(DB::table('sessions')->where('user_id', $this->client->getKey())->count())->toBe(0);
});

it('shows the client their own account activity', function (): void {
    LoginHistory::query()->forceCreate([
        'tenant_id' => $this->tenant->getKey(),
        'authenticatable_type' => CustomerPortalUser::class,
        'authenticatable_id' => $this->client->getKey(),
        'event' => LoginEvent::Failed->value,
        'ip' => '198.51.100.22',
        'browser' => 'Safari',
        'platform' => 'iOS',
        'created_at' => now(),
    ]);

    $this->actingAs($this->client, 'customer')
        ->get(route('portal.devices'))
        ->assertOk()
        ->assertSee('198.51.100.22')
        // Flagged, not filtered -- a failed attempt is what a client needs to
        // notice.
        ->assertSee('Failed sign-in');
});

it('does not show a client an agency user history of the same id', function (): void {
    $staff = User::factory()->create(['id' => $this->client->getKey()]);

    LoginHistory::query()->forceCreate([
        'tenant_id' => $this->tenant->getKey(),
        'authenticatable_type' => User::class,
        'authenticatable_id' => $staff->getKey(),
        'event' => LoginEvent::Login->value,
        'ip' => '198.51.100.99',
        'created_at' => now(),
    ]);

    // The morph TYPE does for history what the guard column does for sessions.
    $this->actingAs($this->client, 'customer')
        ->get(route('portal.devices'))
        ->assertOk()
        ->assertDontSee('198.51.100.99');
});

it('is not reachable without signing in', function (): void {
    $this->get(route('portal.devices'))->assertRedirect();
});

it('is not reachable by an agency user', function (): void {
    // Different guard, different surface. An agency session must not open a
    // portal screen even though both are signed in to the same application.
    $staff = User::factory()->create();

    $this->actingAs($staff, 'web')
        ->get(route('portal.devices'))
        ->assertRedirect();
});
