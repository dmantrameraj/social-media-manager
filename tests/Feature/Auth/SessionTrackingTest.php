<?php

declare(strict_types=1);

use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\CustomerPortalUser;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sessions\GuardAwareSessionHandler;
use App\Domain\Tenancy\Services\ProvisionTenantService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/*
 | sessions.guard has existed since the first migration with a comment saying a
 | custom handler populates it. None did, so the column was always null and
 | device listing could not be built on it.
 |
 | The handler is exercised directly here because the test suite runs on the
 | array session driver: going through HTTP would assert nothing about a
 | database handler that never ran.
 */

beforeEach(function (): void {
    seedPermissions();

    $this->owner = User::factory()->create();
    $this->tenant = app(ProvisionTenantService::class)->execute($this->owner, 'Bright Digital');
    $this->owner = $this->owner->fresh();

    actingForTenant($this->tenant);

    $this->handler = new GuardAwareSessionHandler(
        DB::connection(),
        'sessions',
        120,
        app(),
    );
});

it('records the web guard and the user', function (): void {
    Auth::guard('web')->login($this->owner);

    $this->handler->write('session-a', 'payload');

    $row = DB::table('sessions')->where('id', 'session-a')->first();

    expect($row->user_id)->toBe($this->owner->getKey())
        ->and($row->guard)->toBe('web');
});

it('records the customer guard for a portal login', function (): void {
    /*
     | The reason the column exists. Laravel's handler asks the DEFAULT guard
     | for an id, so every portal session would be stored with no user at all.
     */
    $brand = Customer::factory()->create(['tenant_id' => $this->tenant->getKey()]);

    $client = CustomerPortalUser::factory()->create([
        'tenant_id' => $this->tenant->getKey(),
    ]);
    $client->customers()->attach($brand->getKey(), [
        'tenant_id' => $this->tenant->getKey(),
        'role' => 'approver',
    ]);

    Auth::guard('customer')->login($client);

    $this->handler->write('session-b', 'payload');

    $row = DB::table('sessions')->where('id', 'session-b')->first();

    expect($row->user_id)->toBe($client->getKey())
        ->and($row->guard)->toBe('customer');
});

it('clears the user once nobody is signed in', function (): void {
    /*
     | A session row is rewritten on every request, logout included. Leaving the
     | old values would keep a signed-out session listed as an active device --
     | exactly what somebody checks this screen to rule out.
     */
    Auth::guard('web')->login($this->owner);
    $this->handler->write('session-c', 'payload');

    Auth::guard('web')->logout();
    $this->handler->write('session-c', 'payload');

    $row = DB::table('sessions')->where('id', 'session-c')->first();

    expect($row->user_id)->toBeNull()
        ->and($row->guard)->toBeNull();
});

it('does not confuse two ids that happen to match across guards', function (): void {
    // users and customer_portal_users are separate tables with overlapping ids,
    // so user_id alone cannot identify anybody.
    Auth::guard('web')->login($this->owner);
    $this->handler->write('session-d', 'payload');

    $rows = DB::table('sessions')->where('id', 'session-d')->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->guard)->toBe('web');
});
