<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Customers\Models\Customer;
use App\Domain\Customers\Services\CreateCustomerService;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Services\ProvisionTenantService;

/*
 | settings.view and settings.update have been in the permission catalogue
 | since Step 5 and governed nothing. An agency that signed up with the wrong
 | timezone was stuck with it -- and since CreateCustomerService stamps each
 | new brand from the tenant's timezone, the mistake was inherited by every
 | brand created afterwards, with no way to correct the source.
 */

beforeEach(function (): void {
    seedPermissions();

    $this->owner = User::factory()->create();
    $this->tenant = app(ProvisionTenantService::class)->execute($this->owner, 'Bright Digital');
    $this->owner = $this->owner->fresh();

    actingForTenant($this->tenant);
});

function asWorkspaceAdmin(User $user)
{
    return test()->actingAs($user, 'web')->withSession([
        config('tenancy.resolution.session_key', 'tenant_id') => test()->tenant->getKey(),
    ]);
}

// -------------------------------------------------------------------- saving

it('saves the workspace name and timezone', function (): void {
    asWorkspaceAdmin($this->owner)
        ->put(route('agency.settings.update'), [
            'name' => 'Bright Digital Ltd',
            'timezone' => 'Asia/Kolkata',
        ])
        ->assertRedirect();

    $fresh = $this->tenant->fresh();

    expect($fresh->name)->toBe('Bright Digital Ltd')
        ->and($fresh->timezone)->toBe('Asia/Kolkata');
});

it('is the source new brands inherit their timezone from', function (): void {
    // The reason this screen matters rather than being cosmetic.
    asWorkspaceAdmin($this->owner)->put(route('agency.settings.update'), [
        'name' => 'Bright Digital',
        'timezone' => 'Asia/Kolkata',
    ]);

    $brand = app(CreateCustomerService::class)->execute(
        $this->tenant->fresh(),
        $this->owner,
        ['name' => 'Roast House'],
    );

    expect($brand->timezone)->toBe('Asia/Kolkata');
});

it('leaves existing brands on the timezone they were created with', function (): void {
    /*
     | A brand's timezone is snapshotted at creation so scheduling never walks
     | back to the agency on a hot path. Rewriting them here would silently
     | move every already-scheduled post for every client, which is why the
     | screen says so rather than quietly doing it.
     */
    $existing = Customer::factory()->create([
        'tenant_id' => $this->tenant->getKey(),
        'timezone' => 'Europe/London',
    ]);

    asWorkspaceAdmin($this->owner)->put(route('agency.settings.update'), [
        'name' => 'Bright Digital',
        'timezone' => 'Asia/Kolkata',
    ]);

    expect($existing->fresh()->timezone)->toBe('Europe/London');
});

// ---------------------------------------------------------------- validation

it('refuses a timezone the system does not know', function (): void {
    // Checked against the system list rather than a pattern: an identifier
    // that merely looks plausible still throws when Carbon uses it, and it
    // would do so on the scheduling path rather than here.
    asWorkspaceAdmin($this->owner)
        ->put(route('agency.settings.update'), [
            'name' => 'Bright Digital',
            'timezone' => 'Mars/Olympus_Mons',
        ])
        ->assertSessionHasErrors('timezone');

    expect($this->tenant->fresh()->timezone)->not->toBe('Mars/Olympus_Mons');
});

it('requires a name', function (): void {
    asWorkspaceAdmin($this->owner)
        ->put(route('agency.settings.update'), ['name' => '', 'timezone' => 'UTC'])
        ->assertSessionHasErrors('name');
});

it('cannot be used to change lifecycle state', function (): void {
    // status and the retention clock are lifecycle-owned. The model guards
    // them, and this asserts a crafted payload cannot reach them.
    $before = $this->tenant->status;

    asWorkspaceAdmin($this->owner)->put(route('agency.settings.update'), [
        'name' => 'Bright Digital',
        'timezone' => 'UTC',
        'status' => 'active',
        'trial_ends_at' => now()->addYears(10)->toDateTimeString(),
    ]);

    expect($this->tenant->fresh()->status)->toBe($before);
});

// ------------------------------------------------------------- authorisation

it('refuses a member without the view permission', function (): void {
    $designer = memberWithRole($this->tenant, 'Designer');

    asWorkspaceAdmin($designer)
        ->get(route('agency.settings.edit'))
        ->assertForbidden();
});

it('does not touch another workspace', function (): void {
    $otherOwner = User::factory()->create();
    $otherTenant = app(ProvisionTenantService::class)->execute($otherOwner, 'Rival Agency');

    // Acting for our own tenant, the context resolves to ours -- there is no
    // id in the URL to point elsewhere, which is the point.
    asWorkspaceAdmin($this->owner)->put(route('agency.settings.update'), [
        'name' => 'Renamed By The Wrong Agency',
        'timezone' => 'UTC',
    ]);

    expect($otherTenant->fresh()->name)->toBe('Rival Agency');
});

// -------------------------------------------------------------------- record

it('audits the change with what it was before', function (): void {
    asWorkspaceAdmin($this->owner)->put(route('agency.settings.update'), [
        'name' => 'Bright Digital Ltd',
        'timezone' => 'Asia/Kolkata',
    ]);

    $entry = AuditLog::query()->where('action', 'tenancy.settings_updated')->latest('id')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->old_values['timezone'])->toBe('UTC')
        ->and($entry->new_values['timezone'])->toBe('Asia/Kolkata');
});

// ----------------------------------------------------------------- reachable

it('is reachable from the navigation', function (): void {
    // A screen nothing links to is a screen nobody uses.
    asWorkspaceAdmin($this->owner)
        ->get(route('agency.dashboard'))
        ->assertOk()
        ->assertSee('Settings');
});
