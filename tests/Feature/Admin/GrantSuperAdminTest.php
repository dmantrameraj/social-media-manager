<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Models\CustomerPortalUser;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Services\ProvisionTenantService;
use Illuminate\Database\Eloquent\MassAssignmentException;

/*
 | Granting platform Super Admin.
 |
 | The User model has said since Phase 1 that is_super_admin "is settable only
 | through an audited console command". The guard against mass assignment was
 | there and correct; the command was not written, so the entire /admin surface
 | -- 38 tests' worth of working screens -- could only be reached by editing
 | the database by hand.
 */

beforeEach(function (): void {
    seedPermissions();

    $this->owner = User::factory()->create(['email' => 'owner@agency.test']);
    $this->tenant = app(ProvisionTenantService::class)->execute($this->owner, 'Bright Digital');
    $this->owner = $this->owner->fresh();
});

it('grants super admin to an account', function (): void {
    $this->artisan('platform:super-admin', ['email' => 'owner@agency.test', '--force' => true])
        ->assertSuccessful();

    expect($this->owner->fresh()->isSuperAdmin())->toBeTrue();
});

it('revokes it again', function (): void {
    $this->artisan('platform:super-admin', ['email' => 'owner@agency.test', '--force' => true]);

    $this->artisan('platform:super-admin', [
        'email' => 'owner@agency.test',
        '--revoke' => true,
        '--force' => true,
    ])->assertSuccessful();

    expect($this->owner->fresh()->isSuperAdmin())->toBeFalse();
});

it('records who was given the platform highest privilege', function (): void {
    /*
     | Who holds this, and when they were given it, is the first question asked
     | after any incident. The model's docblock calls the command "audited" and
     | this is what makes that true.
     */
    $this->artisan('platform:super-admin', ['email' => 'owner@agency.test', '--force' => true]);

    $entry = AuditLog::query()->where('action', 'user.super_admin_granted')->sole();

    expect($entry->auditable_id)->toEqual($this->owner->getKey())
        ->and($entry->new_values['is_super_admin'])->toBeTrue();
});

it('does nothing to an account that already has it', function (): void {
    $this->artisan('platform:super-admin', ['email' => 'owner@agency.test', '--force' => true]);
    $this->artisan('platform:super-admin', ['email' => 'owner@agency.test', '--force' => true])
        ->assertSuccessful();

    // Not a second audit entry: nothing changed, so nothing is recorded.
    expect(AuditLog::query()->where('action', 'user.super_admin_granted')->count())->toBe(1);
});

it('fails on an address that has no account', function (): void {
    $this->artisan('platform:super-admin', ['email' => 'nobody@nowhere.test', '--force' => true])
        ->assertFailed();
});

it('finds a user without a tenant context', function (): void {
    /*
     | The command runs from a console with no tenant resolved, and a Super
     | Admin is a platform principal rather than a member of any one agency.
     | Without withoutGlobalScopes the tenant scope hides every candidate and
     | the command reports that a real account does not exist.
     */
    withoutTenantContext();

    $this->artisan('platform:super-admin', ['email' => 'owner@agency.test', '--force' => true])
        ->assertSuccessful();

    expect($this->owner->fresh()->isSuperAdmin())->toBeTrue();
});

it('will not grant it to a portal user', function (): void {
    // Different guard, different table. A client must never be evaluated for
    // this privilege at all, let alone hold it.
    $portalUser = CustomerPortalUser::factory()->create([
        'tenant_id' => $this->tenant->getKey(),
        'email' => 'client@brand.test',
    ]);

    $this->artisan('platform:super-admin', ['email' => 'client@brand.test', '--force' => true])
        ->assertFailed();

    expect($portalUser->fresh()->getAttribute('is_super_admin'))->toBeNull();
});

it('says two-factor is still required', function (): void {
    /*
     | The middleware bounces a Super Admin without 2FA to enrolment. Saying so
     | here means somebody who has just been granted access is not left
     | wondering why the screen they were promised refuses them.
     */
    $this->artisan('platform:super-admin', ['email' => 'owner@agency.test', '--force' => true])
        ->expectsOutputToContain('Two-factor authentication is mandatory');
});

it('does not mention two-factor for somebody who already has it', function (): void {
    $this->owner->forceFill(['two_factor_confirmed_at' => now()])->save();

    $this->artisan('platform:super-admin', ['email' => 'owner@agency.test', '--force' => true])
        ->doesntExpectOutputToContain('Two-factor authentication is mandatory');
});

it('cannot be set by mass assignment', function (): void {
    /*
     | The reason the command exists. A fillable path to this column is a
     | privilege-escalation vulnerability, and the console is the only door.
     |
     | It THROWS rather than silently discarding, which is the stronger
     | guarantee: a silent discard means code that thinks it granted the
     | privilege carries on believing it did.
     */
    $user = User::factory()->create();

    $user->fill(['is_super_admin' => true]);
})->throws(MassAssignmentException::class);
