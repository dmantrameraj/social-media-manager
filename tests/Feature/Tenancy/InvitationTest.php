<?php

declare(strict_types=1);

use App\Domain\Billing\Entitlements\EntitlementResolver;
use App\Domain\Billing\Entitlements\Exceptions\EntitlementExceeded;
use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Exceptions\InvitationUnusable;
use App\Domain\Tenancy\Models\Invitation;
use App\Domain\Tenancy\Services\AcceptInvitationService;
use App\Domain\Tenancy\Services\InviteTeamMemberService;
use App\Domain\Tenancy\Services\ProvisionTenantService;

beforeEach(function (): void {
    seedPermissions();
    $this->invite = app(InviteTeamMemberService::class);
    $this->accept = app(AcceptInvitationService::class);

    $this->owner = User::factory()->create();
    $this->tenant = app(ProvisionTenantService::class)->execute($this->owner, 'Bright Digital');
    $this->owner = $this->owner->fresh();

    givePlanLimit($this->tenant->getKey(), 'team_members.max', 10);
    app(EntitlementResolver::class)->forget($this->tenant);

    actingForTenant($this->tenant);
});

it('stores only the hash of the invitation token', function (): void {
    ['invitation' => $invitation, 'token' => $token] = $this->invite->execute(
        $this->tenant, $this->owner, 'new@example.com', 'Manager'
    );

    // A database read must not yield anything usable as a credential.
    expect($invitation->token_hash)->toBe(hash('sha256', $token))
        ->and($invitation->token_hash)->not->toBe($token)
        ->and(json_encode($invitation->toArray()))->not->toContain($token);
});

it('joins the invitee to the workspace with the invited role', function (): void {
    ['token' => $token] = $this->invite->execute(
        $this->tenant, $this->owner, 'creator@example.com', 'Content Creator'
    );

    $invitee = User::factory()->create(['email' => 'creator@example.com']);
    $this->accept->execute($token, $invitee);

    $invitee = $invitee->fresh();
    actingForTenant($this->tenant);

    expect($invitee->belongsToTenant($this->tenant))->toBeTrue()
        ->and($invitee->can('posts.create'))->toBeTrue()
        // Content Creator does not carry publish rights.
        ->and($invitee->can('posts.publish'))->toBeFalse();
});

it('assigns the invitee to the named brands', function (): void {
    $brandA = Customer::factory()->create(['tenant_id' => $this->tenant->getKey()]);
    $brandB = Customer::factory()->create(['tenant_id' => $this->tenant->getKey()]);

    ['token' => $token] = $this->invite->execute(
        $this->tenant, $this->owner, 'creator@example.com', 'Content Creator',
        [$brandA->getKey()]
    );

    $invitee = User::factory()->create(['email' => 'creator@example.com']);
    $this->accept->execute($token, $invitee);

    $invitee = $invitee->fresh();
    actingForTenant($this->tenant);

    expect($invitee->canAccessCustomer($brandA))->toBeTrue()
        ->and($invitee->canAccessCustomer($brandB))->toBeFalse();
});

it('refuses to invite against a brand from another tenant', function (): void {
    $otherTenant = app(ProvisionTenantService::class)
        ->execute(User::factory()->create(), 'Other Agency');

    withoutTenantContext();
    $foreignBrand = Customer::factory()->create(['tenant_id' => $otherTenant->getKey()]);
    actingForTenant($this->tenant);

    expect(fn () => $this->invite->execute(
        $this->tenant, $this->owner, 'creator@example.com', 'Manager', [$foreignBrand->getKey()]
    ))->toThrow(RuntimeException::class);
});

it('refuses a token presented by a different email address', function (): void {
    ['token' => $token] = $this->invite->execute(
        $this->tenant, $this->owner, 'intended@example.com', 'Manager'
    );

    // A forwarded invitation email must not grant access to whoever opens it.
    $someoneElse = User::factory()->create(['email' => 'someone-else@example.com']);

    expect(fn () => $this->accept->execute($token, $someoneElse))
        ->toThrow(InvitationUnusable::class);
});

it('rejects an expired invitation with a specific reason', function (): void {
    ['invitation' => $invitation, 'token' => $token] = $this->invite->execute(
        $this->tenant, $this->owner, 'late@example.com', 'Manager'
    );

    Invitation::query()->acrossTenants()
        ->whereKey($invitation->getKey())
        ->update(['expires_at' => now()->subDay()]);

    $invitee = User::factory()->create(['email' => 'late@example.com']);

    try {
        $this->accept->execute($token, $invitee);
        $this->fail('Expected InvitationUnusable.');
    } catch (InvitationUnusable $e) {
        expect($e->getMessage())->toContain('expired');
    }
});

it('rejects a revoked invitation', function (): void {
    ['invitation' => $invitation, 'token' => $token] = $this->invite->execute(
        $this->tenant, $this->owner, 'revoked@example.com', 'Manager'
    );

    $this->invite->revoke($invitation);

    $invitee = User::factory()->create(['email' => 'revoked@example.com']);

    expect(fn () => $this->accept->execute($token, $invitee))
        ->toThrow(InvitationUnusable::class);
});

it('cannot be accepted twice', function (): void {
    ['token' => $token] = $this->invite->execute(
        $this->tenant, $this->owner, 'once@example.com', 'Manager'
    );

    $invitee = User::factory()->create(['email' => 'once@example.com']);
    $this->accept->execute($token, $invitee);

    expect(fn () => $this->accept->execute($token, $invitee))
        ->toThrow(InvitationUnusable::class);
});

it('revokes an outstanding invitation when the same address is re-invited', function (): void {
    ['token' => $firstToken] = $this->invite->execute(
        $this->tenant, $this->owner, 'dup@example.com', 'Manager'
    );

    ['token' => $secondToken] = $this->invite->execute(
        $this->tenant, $this->owner, 'dup@example.com', 'Manager'
    );

    $invitee = User::factory()->create(['email' => 'dup@example.com']);

    // Two usable tokens for one seat would be a hole; the first must die.
    expect(fn () => $this->accept->execute($firstToken, $invitee))
        ->toThrow(InvitationUnusable::class);

    $this->accept->execute($secondToken, $invitee);
    expect($invitee->fresh()->belongsToTenant($this->tenant))->toBeTrue();
});

it('rejects an unknown token without revealing anything', function (): void {
    $user = User::factory()->create();

    expect(fn () => $this->accept->execute(bin2hex(random_bytes(32)), $user))
        ->toThrow(InvitationUnusable::class);
});

it('refuses to invite someone who is already a member', function (): void {
    expect(fn () => $this->invite->execute(
        $this->tenant, $this->owner, $this->owner->email, 'Manager'
    ))->toThrow(RuntimeException::class);
});

it('enforces the team member limit at invite time', function (): void {
    givePlanLimit($this->tenant->getKey(), 'team_members.max', 1);
    app(EntitlementResolver::class)->forget($this->tenant);

    // The owner already occupies the single seat.
    expect(fn () => $this->invite->execute(
        $this->tenant, $this->owner, 'nope@example.com', 'Manager'
    ))->toThrow(EntitlementExceeded::class);
});

it('refuses an unknown role', function (): void {
    expect(fn () => $this->invite->execute(
        $this->tenant, $this->owner, 'x@example.com', 'Supreme Overlord'
    ))->toThrow(RuntimeException::class);
});
