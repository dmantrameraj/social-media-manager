<?php

declare(strict_types=1);

use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Services\ProvisionTenantService;

/*
 | An agency reading its own audit trail.
 |
 | Every action staff take has been recorded since Phase 1, and the only screen
 | that read any of it was /admin/audit behind platform.audit.view -- so a
 | Super Admin could read any agency's trail and the agency could not read its
 | own. audit_logs.view has been granted to Agency Owner and Agency Admin since
 | Step 5 and governed nothing. Found by the unreachable-code sweep.
 |
 | The isolation tests below matter more than usual here: AuditLog deliberately
 | does NOT use BelongsToTenant -- a failed login happens before any tenant is
 | resolved and the row must still be written -- so there is no global scope to
 | fall back on and the controller scopes by hand.
 */

beforeEach(function (): void {
    seedPermissions();

    $this->owner = User::factory()->create(['name' => 'Dana Owner']);
    $this->tenant = app(ProvisionTenantService::class)->execute($this->owner, 'Bright Digital');
    $this->owner = $this->owner->fresh();

    actingForTenant($this->tenant);

    $this->brand = Customer::factory()->create([
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'Roast House',
    ]);
});

it('shows what happened in this workspace', function (): void {
    app(AuditLogger::class)->log(
        action: 'customer.archived',
        auditable: $this->brand,
        actor: $this->owner,
    );

    asAgencyUser($this->owner)
        ->get(route('agency.audit'))
        ->assertOk()
        ->assertSee('customer.archived')
        // A name, not "user #3": "who archived that brand?" is the question
        // this screen exists to answer.
        ->assertSee('Dana Owner');
});

it('never shows another agency trail', function (): void {
    /*
     | The test that matters. audit_logs has no global scope, so nothing but
     | the controller's own where() stands between one agency and another's
     | record of everything they have ever done.
     */
    [$rival, $rivalOwner] = provisionTenant('Rival Agency');
    actingForTenant($rival);

    $rivalBrand = Customer::factory()->create([
        'tenant_id' => $rival->getKey(),
        'name' => 'Their Secret Client',
    ]);

    app(AuditLogger::class)->log(
        action: 'rival.only.action',
        auditable: $rivalBrand,
        actor: $rivalOwner,
    );

    actingForTenant($this->tenant);

    asAgencyUser($this->owner)
        ->get(route('agency.audit'))
        ->assertOk()
        ->assertDontSee('rival.only.action')
        ->assertDontSee('Their Secret Client');
});

it('does not offer another agency staff in the filter', function (): void {
    // Listing every user would leak the existence of staff elsewhere through a
    // dropdown, which is the same leak the tenant scope exists to prevent.
    [$rival, $rivalOwner] = provisionTenant('Rival Agency');
    $rivalOwner->forceFill(['name' => 'Someone Elsewhere'])->save();

    actingForTenant($this->tenant);

    asAgencyUser($this->owner)
        ->get(route('agency.audit'))
        ->assertOk()
        ->assertDontSee('Someone Elsewhere');
});

it('needs the audit permission', function (): void {
    // A Manager runs the content operation; reading who did what is an owner
    // and admin concern, and the role catalogue says so.
    asAgencyUser(memberWithRole($this->tenant, 'Manager'))
        ->get(route('agency.audit'))
        ->assertForbidden();
});

it('is offered to an agency admin', function (): void {
    asAgencyUser(memberWithRole($this->tenant, 'Agency Admin'))
        ->get(route('agency.audit'))
        ->assertOk();
});

it('filters by action', function (): void {
    app(AuditLogger::class)->log(action: 'customer.archived', auditable: $this->brand, actor: $this->owner);
    app(AuditLogger::class)->log(action: 'post.rescheduled', auditable: $this->brand, actor: $this->owner);

    /*
     | Asserted on the result set, not the rendered page. The filter dropdown
     | legitimately lists every action this agency has produced, so
     | assertDontSee('customer.archived') would match the <option> and fail for
     | a reason that has nothing to do with filtering.
     */
    $logs = asAgencyUser($this->owner)
        ->get(route('agency.audit', ['action' => 'post.']))
        ->assertOk()
        ->viewData('logs');

    expect($logs->pluck('action')->all())->toBe(['post.rescheduled']);
});

it('filters by person', function (): void {
    $colleague = memberWithRole($this->tenant, 'Manager');
    $colleague->forceFill(['name' => 'Sam Manager'])->save();

    app(AuditLogger::class)->log(action: 'owner.did.this', auditable: $this->brand, actor: $this->owner);
    app(AuditLogger::class)->log(action: 'colleague.did.this', auditable: $this->brand, actor: $colleague);

    $logs = asAgencyUser($this->owner)
        ->get(route('agency.audit', ['actor' => $colleague->getKey()]))
        ->assertOk()
        ->viewData('logs');

    expect($logs->pluck('action')->all())->toBe(['colleague.did.this'])
        ->and($logs->pluck('actor_id')->unique()->all())->toBe([$colleague->getKey()]);
});

it('shows no secret, because none was ever written', function (): void {
    /*
     | §64 forbids a secret in an audit record at all. That is a stronger
     | guarantee than hiding one at render time, and this asserts the stronger
     | one: the redactor runs on WRITE, so the column cannot carry a secret for
     | this page to leak.
     */
    app(AuditLogger::class)->log(
        action: 'social_credential.updated',
        auditable: $this->brand,
        newValues: ['client_secret' => 'super-secret-value', 'changed' => ['label']],
        actor: $this->owner,
    );

    $entry = AuditLog::query()->where('action', 'social_credential.updated')->sole();

    expect(json_encode($entry->new_values))->not->toContain('super-secret-value');

    asAgencyUser($this->owner)
        ->get(route('agency.audit'))
        ->assertOk()
        ->assertDontSee('super-secret-value');
});

it('is not reachable without signing in', function (): void {
    $this->get(route('agency.audit'))->assertRedirect();
});
