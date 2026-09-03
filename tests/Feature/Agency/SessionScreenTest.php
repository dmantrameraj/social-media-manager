<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Services\ProvisionTenantService;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | Until this screen existed the only way to end a session on another device was
 | to change your password and hope every device was logged out by it.
 */

beforeEach(function (): void {
    seedPermissions();

    $this->owner = User::factory()->create();
    $this->tenant = app(ProvisionTenantService::class)->execute($this->owner, 'Bright Digital');
    $this->owner = $this->owner->fresh();

    actingForTenant($this->tenant);
});

function asSessionOwner(User $user)
{
    return test()->actingAs($user, 'web')->withSession([
        config('tenancy.resolution.session_key', 'tenant_id') => test()->tenant->getKey(),
    ]);
}

/** A stored session row, as the handler would have written it. */
function storedSession(string $id, ?int $userId, string $guard = 'web', string $agent = 'Mozilla/5.0 (Windows NT 10.0) Chrome/120'): void
{
    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $userId,
        'guard' => $guard,
        'ip_address' => '203.0.113.9',
        'user_agent' => $agent,
        'payload' => base64_encode('x'),
        'last_activity' => now()->timestamp,
    ]);
}

// ------------------------------------------------------------------- listing

it('lists your own devices', function (): void {
    storedSession('mine-1', $this->owner->getKey());

    asSessionOwner($this->owner)
        ->get(route('agency.sessions.index'))
        ->assertOk()
        ->assertSee('Chrome on Windows');
});

it('does not list somebody else is devices', function (): void {
    $other = User::factory()->create();
    storedSession('theirs', $other->getKey(), agent: 'Mozilla/5.0 (Macintosh) Firefox/121');

    asSessionOwner($this->owner)
        ->get(route('agency.sessions.index'))
        ->assertOk()
        ->assertDontSee('Firefox on macOS');
});

it('does not list a portal session that shares an id', function (): void {
    /*
     | users and customer_portal_users are separate tables whose ids overlap, so
     | filtering on user_id alone would show a client's devices to a staff
     | member who happens to have the same primary key.
     */
    storedSession('portal', $this->owner->getKey(), guard: 'customer', agent: 'Mozilla/5.0 (iPhone) Safari/605');

    asSessionOwner($this->owner)
        ->get(route('agency.sessions.index'))
        ->assertOk()
        ->assertDontSee('Safari on iOS');
});

// ------------------------------------------------------------------ revoking

it('signs out one device', function (): void {
    storedSession('other-device', $this->owner->getKey());

    asSessionOwner($this->owner)
        ->delete(route('agency.sessions.destroy', 'other-device'))
        ->assertRedirect();

    expect(DB::table('sessions')->where('id', 'other-device')->exists())->toBeFalse();
});

it('refuses to sign out somebody else is device', function (): void {
    // The id is a primary key arriving from a form, which is exactly the input
    // that must never identify a row on its own.
    $other = User::factory()->create();
    storedSession('not-yours', $other->getKey());

    asSessionOwner($this->owner)
        ->delete(route('agency.sessions.destroy', 'not-yours'))
        ->assertRedirect();

    expect(DB::table('sessions')->where('id', 'not-yours')->exists())->toBeTrue();
});

it('refuses to sign out the device you are using', function (): void {
    /*
     | Doing it here would log you out mid-action with no explanation, so the
     | row carries no control and a forged request is refused.
     |
     | A feature test gets a FRESH random session id on every dispatched
     | request unless the session cookie is carried along explicitly -- so a
     | plain ->get() then ->delete() run as two unrelated sessions and the id
     | captured after the first never matches the second. Fixed by choosing
     | the id ourselves and pinning it to the request with an unencrypted
     | cookie, which is what makes $request->session()->getId() inside the
     | controller equal the id in the URL.
     |
     | withUnencryptedCookie only stops the TEST encrypting the outgoing
     | value -- EncryptCookies still tries to decrypt whatever arrives, fails
     | on a plaintext value, and silently nulls it. The middleware itself has
     | to be skipped for the raw id to survive into the request; that is a
     | test-only bypass and touches no production cookie configuration.
     */
    $current = Str::random(40);

    storedSession($current, $this->owner->getKey());

    $this->withoutMiddleware(EncryptCookies::class)
        ->actingAs($this->owner, 'web')
        ->withUnencryptedCookie(config('session.cookie'), $current)
        ->withSession([
            config('tenancy.resolution.session_key', 'tenant_id') => $this->tenant->getKey(),
        ])
        ->delete(route('agency.sessions.destroy', $current))
        ->assertSessionHas('error');

    expect(DB::table('sessions')->where('id', $current)->exists())->toBeTrue();
});

it('signs out every other device at once', function (): void {
    // What somebody actually wants when they think an account is compromised.
    storedSession('a', $this->owner->getKey());
    storedSession('b', $this->owner->getKey());
    storedSession('c', $this->owner->getKey());

    asSessionOwner($this->owner)
        ->delete(route('agency.sessions.destroy-others'))
        ->assertRedirect();

    expect(DB::table('sessions')->where('user_id', $this->owner->getKey())->count())->toBe(0);
});

it('leaves other people signed in when you sign out your own devices', function (): void {
    $other = User::factory()->create();
    storedSession('mine', $this->owner->getKey());
    storedSession('theirs', $other->getKey());

    asSessionOwner($this->owner)->delete(route('agency.sessions.destroy-others'));

    expect(DB::table('sessions')->where('id', 'theirs')->exists())->toBeTrue();
});

// -------------------------------------------------------------------- record

it('audits a revocation', function (): void {
    storedSession('audited', $this->owner->getKey());

    asSessionOwner($this->owner)->delete(route('agency.sessions.destroy', 'audited'));

    expect(AuditLog::query()->where('action', 'auth.session_revoked')->exists())->toBeTrue();
});

// ------------------------------------------------------------------ reachable

it('is reachable from the navigation', function (): void {
    // A screen nothing links to is a screen nobody uses.
    asSessionOwner($this->owner)
        ->get(route('agency.dashboard'))
        ->assertOk()
        ->assertSee('Signed-in devices');
});
