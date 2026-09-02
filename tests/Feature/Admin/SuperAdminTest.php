<?php

declare(strict_types=1);

use App\Domain\Access\PermissionCatalogue;
use App\Domain\AI\Credits\CreditLedger;
use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Billing\Entitlements\EntitlementResolver;
use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\ImpersonationSession;
use App\Domain\Platform\Services\ImpersonationService;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Services\ProvisionTenantService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function (): void {
    seedPermissions();

    $this->owner = User::factory()->create();
    $this->tenant = app(ProvisionTenantService::class)->execute($this->owner, 'Bright Digital');
    $this->owner = $this->owner->fresh();

    $this->admin = superAdmin();
});

/**
 * A Super Admin with 2FA already confirmed.
 *
 * Both flags matter: EnsureSuperAdmin refuses a Super Admin who has not
 * enrolled, so a helper that only sets is_super_admin would send every test
 * into the enrolment redirect instead of the page under test.
 */
function superAdmin(): User
{
    $user = User::factory()->create();

    $user->forceFill([
        'is_super_admin' => true,
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['aaaa-bbbb'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $user->fresh();
}

function asAdmin(User $admin)
{
    return test()->actingAs($admin, 'web');
}

/** @return list<string> */
function adminRouteNames(): array
{
    return collect(Route::getRoutes())
        ->map(fn ($route) => (string) $route->getName())
        ->filter(fn (string $name): bool => str_starts_with($name, 'admin.'))
        ->values()
        ->all();
}

// ------------------------------------------------------------------- gating

it('hides the whole admin surface from a signed-out visitor', function (): void {
    $this->get('/admin')->assertRedirect(route('login'));
});

it('answers 404 rather than 403 for a non-admin, so the surface is not discoverable', function (): void {
    // 403 would confirm /admin exists and is worth attacking. 404 does not.
    $this->actingAs($this->owner, 'web')->get('/admin')->assertNotFound();
});

it('refuses a super admin who has not confirmed two-factor', function (): void {
    $unenrolled = User::factory()->create();
    $unenrolled->forceFill(['is_super_admin' => true])->save();

    $this->actingAs($unenrolled->fresh(), 'web')
        ->get('/admin')
        ->assertRedirect(route('two-factor.enrol'));
});

it('sends an unenrolled admin somewhere that actually exists', function (): void {
    // Regression: EnsureSuperAdmin redirected to a route name Fortify does not
    // define, so an unenrolled admin got a RouteNotFoundException.
    expect(Route::has('two-factor.enrol'))->toBeTrue();

    $unenrolled = User::factory()->create();
    $unenrolled->forceFill(['is_super_admin' => true])->save();

    $this->actingAs($unenrolled->fresh(), 'web')
        ->get(route('two-factor.enrol'))
        ->assertOk();
});

it('lets an enrolled super admin in', function (): void {
    $this->actingAs($this->admin, 'web')->get('/admin')->assertOk();
});

it('gates every admin route behind the admin middleware group', function (): void {
    $router = app('router');

    $expand = function (array $middleware) use ($router): array {
        $groups = $router->getMiddlewareGroups();
        $out = [];

        foreach ($middleware as $item) {
            if (isset($groups[$item])) {
                $out = array_merge($out, array_map('strval', $groups[$item]));

                continue;
            }

            $out[] = (string) $item;
        }

        return $out;
    };

    foreach (Route::getRoutes() as $route) {
        $name = (string) $route->getName();

        if (! str_starts_with($name, 'admin.')) {
            continue;
        }

        $middleware = $expand($route->gatherMiddleware());

        // Leaving impersonation is the one deliberate exception: while
        // impersonating, the principal is the customer, so requiring
        // super-admin here would trap the admin inside the account.
        if ($name === 'admin.impersonation.stop') {
            expect(in_array('auth:web', $middleware, true))
                ->toBeTrue("[{$name}] is not behind auth:web.");

            continue;
        }

        expect(in_array('auth:web', $middleware, true))
            ->toBeTrue("[{$name}] is not behind auth:web.");
        expect(in_array('super-admin', $middleware, true))
            ->toBeTrue("[{$name}] does not require a Super Admin.");
    }
});

it('refuses every admin route to a non-admin, not just the dashboard', function (): void {
    foreach (adminRouteNames() as $name) {
        if ($name === 'admin.impersonation.stop') {
            continue;
        }

        $route = Route::getRoutes()->getByName($name);
        $uri = '/'.ltrim(str_replace(
            ['{tenant}', '{user}', '{key}', '{uuid}'],
            [$this->tenant->getRouteKey(), (string) $this->owner->getKey(), 'brands.max', 'x'],
            $route->uri(),
        ), '/');

        $method = collect($route->methods())->first(fn ($m) => $m !== 'HEAD');

        $response = $this->actingAs($this->owner, 'web')->call($method, $uri);

        expect($response->status())->toBe(
            404,
            "[{$name}] answered {$response->status()} for a non-admin; expected 404.",
        );
    }
});

// ------------------------------------------------- the credentials guarantee

it('never exposes agency provider credentials on any admin screen', function (): void {
    /*
     | The Phase 1 exit criterion from docs/10-SECURITY.md section 5: agencies
     | supply their own provider credentials on the understanding that platform
     | staff cannot read them. This asserts it against the rendered HTML of
     | every admin GET screen, not against intent.
     */
    DB::table('social_app_credentials')->insert([
        'tenant_id' => $this->tenant->getKey(),
        'provider_key' => 'fake',
        'label' => 'Primary app',
        'client_id' => encrypt('CLIENT-ID-VALUE'),
        'client_secret' => encrypt('SUPER-SECRET-VALUE-DO-NOT-SHOW'),
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $screens = [
        route('admin.dashboard'),
        route('admin.tenants.index'),
        route('admin.tenants.create'),
        route('admin.tenants.show', $this->tenant),
        route('admin.plans.index'),
        route('admin.audit.index'),
        route('admin.jobs.index'),
    ];

    foreach ($screens as $url) {
        $body = $this->actingAs($this->admin, 'web')->get($url)->assertOk()->getContent();

        expect(str_contains($body, 'SUPER-SECRET-VALUE-DO-NOT-SHOW'))
            ->toBeFalse("[{$url}] rendered an agency app secret.");
        expect(str_contains($body, 'CLIENT-ID-VALUE'))
            ->toBeFalse("[{$url}] rendered an agency client id.");
        expect(str_contains($body, 'client_secret'))
            ->toBeFalse("[{$url}] rendered the client_secret column.");
    }
});

it('keeps provider secrets out of the audit viewer', function (): void {
    app(AuditLogger::class)->log(
        'social.credentials_updated',
        null,
        newValues: ['client_secret' => 'LEAKED-SECRET-VALUE', 'provider_key' => 'fake'],
        actor: $this->admin,
        tenantId: $this->tenant->getKey(),
    );

    $body = $this->actingAs($this->admin, 'web')
        ->get(route('admin.audit.index'))
        ->assertOk()
        ->getContent();

    expect(str_contains($body, 'LEAKED-SECRET-VALUE'))->toBeFalse(
        'The audit viewer rendered a secret. SecretRedactor should have removed it on write.',
    );
});

// ------------------------------------------------------------- tenant actions

it('suspends an agency and records who did it and why', function (): void {
    $this->actingAs($this->admin, 'web')
        ->post(route('admin.tenants.suspend', $this->tenant), [
            'reason' => 'Chargeback raised on the last two invoices.',
        ])
        ->assertRedirect();

    expect($this->tenant->fresh()->status)->toBe(TenantStatus::Suspended);

    $entry = AuditLog::query()->where('action', 'tenant.suspended_by_admin')->latest('id')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->actor_id)->toBe($this->admin->getKey())
        ->and($entry->new_values['reason'])->toContain('Chargeback');
});

it('refuses a lifecycle change with no stated reason', function (): void {
    $this->actingAs($this->admin, 'web')
        ->post(route('admin.tenants.suspend', $this->tenant), ['reason' => ''])
        ->assertSessionHasErrors('reason');

    expect($this->tenant->fresh()->status)->not->toBe(TenantStatus::Suspended);
});

it('reactivates a suspended agency', function (): void {
    $this->tenant->forceFill(['status' => TenantStatus::Suspended->value])->save();

    $this->actingAs($this->admin, 'web')
        ->post(route('admin.tenants.reactivate', $this->tenant), [
            'reason' => 'Payment recovered and confirmed with the bank.',
        ])
        ->assertRedirect();

    expect($this->tenant->fresh()->permitsProductAccess())->toBeTrue();
});

it('creates an agency without ever setting a password for its owner', function (): void {
    $this->actingAs($this->admin, 'web')
        ->post(route('admin.tenants.store'), [
            'name' => 'Northwind Media',
            'owner_name' => 'Dana Reed',
            'owner_email' => 'dana@northwind.test',
            'reason' => 'Signed annual contract, migrating from a competitor.',
        ])
        ->assertRedirect();

    $created = Tenant::query()->where('name', 'Northwind Media')->first();

    expect($created)->not->toBeNull()
        ->and($created->status)->toBe(TenantStatus::Active);

    // Staff must never choose a customer credential; the owner arrives through
    // the reset flow instead.
    $ownerPassword = DB::table('users')->where('email', 'dana@northwind.test')->value('password');
    expect($ownerPassword)->not->toBeNull();

    foreach (['password', 'secret', '123456'] as $guess) {
        expect(password_verify($guess, $ownerPassword))->toBeFalse();
    }
});

// ------------------------------------------------------------- entitlements

it('overrides a limit and invalidates the cached entitlement', function (): void {
    $resolver = app(EntitlementResolver::class);

    expect($resolver->value($this->tenant, 'brands.max')->source)->toBe('default');

    $this->actingAs($this->admin, 'web')
        ->post(route('admin.tenants.entitlements.store', $this->tenant), [
            'key' => 'brands.max',
            'value_type' => 'limit',
            'value' => 250,
            'reason' => 'Enterprise pilot agreed by the founder.',
        ])
        ->assertRedirect();

    $resolved = $resolver->value($this->tenant->fresh(), 'brands.max');

    expect($resolved->source)->toBe('override')
        ->and($resolved->value)->toBe(250);
});

it('refuses an override on an entitlement key that does not exist', function (): void {
    $this->actingAs($this->admin, 'web')
        ->post(route('admin.tenants.entitlements.store', $this->tenant), [
            'key' => 'brands.maximum',
            'value_type' => 'limit',
            'value' => 250,
            'reason' => 'A typo that must not silently do nothing.',
        ])
        ->assertSessionHasErrors('key');
});

it('refuses a limit override with no value, which would resolve to zero', function (): void {
    $this->actingAs($this->admin, 'web')
        ->post(route('admin.tenants.entitlements.store', $this->tenant), [
            'key' => 'brands.max',
            'value_type' => 'limit',
            'value' => null,
            'reason' => 'Blank value must not lock the tenant out.',
        ])
        ->assertSessionHasErrors('value');
});

it('removes an override and returns the tenant to its plan value', function (): void {
    $resolver = app(EntitlementResolver::class);

    $this->actingAs($this->admin, 'web')->post(
        route('admin.tenants.entitlements.store', $this->tenant),
        ['key' => 'brands.max', 'value_type' => 'limit', 'value' => 250, 'reason' => 'Temporary pilot.'],
    );

    expect($resolver->value($this->tenant->fresh(), 'brands.max')->source)->toBe('override');

    $this->actingAs($this->admin, 'web')->delete(
        route('admin.tenants.entitlements.destroy', [$this->tenant, 'brands.max']),
        ['reason' => 'Pilot ended, back to plan.'],
    )->assertRedirect();

    expect($resolver->value($this->tenant->fresh(), 'brands.max')->source)->toBe('default');
});

// ------------------------------------------------------------------ credits

it('adjusts credits through the ledger rather than editing a balance', function (): void {
    $before = app(CreditLedger::class)->accountFor($this->tenant)->balance;

    $this->actingAs($this->admin, 'web')
        ->post(route('admin.tenants.credits.store', $this->tenant), [
            'delta' => 500,
            'reason' => 'Goodwill after the publishing incident on the 14th.',
        ])
        ->assertRedirect();

    expect(app(CreditLedger::class)->accountFor($this->tenant->fresh())->balance)->toBe($before + 500);

    // The movement leaves a transaction, so the balance is always explicable.
    expect(DB::table('ai_credit_transactions')
        ->where('tenant_id', $this->tenant->getKey())
        ->where('type', 'adjustment')
        ->exists())->toBeTrue();
});

it('refuses a credit adjustment of zero or with no reason', function (): void {
    $this->actingAs($this->admin, 'web')
        ->post(route('admin.tenants.credits.store', $this->tenant), ['delta' => 0, 'reason' => 'Nothing at all.'])
        ->assertSessionHasErrors('delta');

    $this->actingAs($this->admin, 'web')
        ->post(route('admin.tenants.credits.store', $this->tenant), ['delta' => 100, 'reason' => ''])
        ->assertSessionHasErrors('reason');
});

// ------------------------------------------------------------ impersonation

it('starts an impersonation, writes both audit identities and shows the banner', function (): void {
    $this->actingAs($this->admin, 'web')
        ->post(route('admin.impersonation.start', $this->owner), [
            'reason' => 'Investigating the failed publish reported in ticket 4471.',
        ])
        ->assertRedirect(route('agency.dashboard'));

    expect(auth('web')->id())->toBe($this->owner->getKey())
        ->and(session(ImpersonationService::IMPERSONATOR_KEY))->toBe($this->admin->getKey());

    $record = ImpersonationSession::query()->latest('id')->first();

    expect($record)->not->toBeNull()
        ->and($record->super_admin_user_id)->toBe($this->admin->getKey())
        ->and($record->isOpen())->toBeTrue()
        ->and($record->reason)->toContain('4471');

    $entry = AuditLog::query()->where('action', 'impersonation.started')->latest('id')->first();
    expect($entry)->not->toBeNull()->and($entry->actor_id)->toBe($this->admin->getKey());
});

it('refuses to impersonate another super admin', function (): void {
    $peer = superAdmin();

    $this->actingAs($this->admin, 'web')
        ->post(route('admin.impersonation.start', $peer), [
            'reason' => 'This must never be permitted, whatever the stated reason.',
        ])
        ->assertSessionHasErrors('reason');

    expect(auth('web')->id())->toBe($this->admin->getKey())
        ->and(ImpersonationSession::query()->count())->toBe(0);
});

it('refuses to impersonate without a reason', function (): void {
    $this->actingAs($this->admin, 'web')
        ->post(route('admin.impersonation.start', $this->owner), ['reason' => 'help'])
        ->assertSessionHasErrors('reason');

    expect(ImpersonationSession::query()->count())->toBe(0);
});

it('blocks credential, billing and destructive actions while impersonating', function (): void {
    $this->actingAs($this->admin, 'web')->post(
        route('admin.impersonation.start', $this->owner),
        ['reason' => 'Reproducing the scheduling bug from ticket 4471.'],
    );

    // Billing is money; it must not be reachable as the customer.
    $this->get(route('agency.billing'))->assertForbidden();

    // Nor may the admin change how the customer authenticates.
    $this->get(route('two-factor.enrol'))->assertForbidden();
});

it('still allows ordinary read-only work while impersonating', function (): void {
    // The feature is worthless if support cannot see what the customer sees.
    $this->actingAs($this->admin, 'web')->post(
        route('admin.impersonation.start', $this->owner),
        ['reason' => 'Reproducing the scheduling bug from ticket 4471.'],
    );

    $this->get(route('agency.dashboard'))->assertOk();
    $this->get(route('agency.brands.index'))->assertOk();
});

it('shows the impersonation banner on every agency page', function (): void {
    $this->actingAs($this->admin, 'web')->post(
        route('admin.impersonation.start', $this->owner),
        ['reason' => 'Reproducing the scheduling bug from ticket 4471.'],
    );

    foreach ([route('agency.dashboard'), route('agency.brands.index')] as $url) {
        $this->get($url)
            ->assertOk()
            ->assertSee('Exit impersonation')
            ->assertSee('Impersonating');
    }
});

it('has no blocked-route pattern that matches nothing', function (): void {
    /*
     | Regression, and the reason this test exists at all: the list carried
     | `agency.billing.*`, which does NOT match the route actually named
     | `agency.billing` -- Str::is compiles the pattern to a regex requiring the
     | separating dot. Billing stayed reachable while impersonating, and the
     | config looked completely correct.
     |
     | A pattern that matches no route is either a typo or a rename nobody
     | followed through. Either way it is a hole that reads as a protection.
     */
    $names = collect(Route::getRoutes())
        ->map(fn ($route) => (string) $route->getName())
        ->filter()
        ->values();

    foreach ((array) config('platform.impersonation.blocked_routes', []) as $pattern) {
        $pattern = (string) $pattern;

        $matched = $names->contains(
            fn (string $name): bool => Str::is($pattern, $name)
                || (str_ends_with($pattern, '.*') && $name === substr($pattern, 0, -2)),
        );

        expect($matched)->toBeTrue(
            "Blocked-route pattern [{$pattern}] matches no registered route, so it protects nothing.",
        );
    }
});

it('never blocks the exit, or the admin would be trapped in the account', function (): void {
    $this->actingAs($this->admin, 'web')->post(
        route('admin.impersonation.start', $this->owner),
        ['reason' => 'Reproducing the scheduling bug from ticket 4471.'],
    );

    $this->delete(route('admin.impersonation.stop'))->assertRedirect(route('admin.dashboard'));

    expect(auth('web')->id())->toBe($this->admin->getKey())
        ->and(session(ImpersonationService::IMPERSONATOR_KEY))->toBeNull();

    $record = ImpersonationSession::query()->latest('id')->first();
    expect($record->isOpen())->toBeFalse();

    expect(AuditLog::query()->where('action', 'impersonation.ended')->exists())->toBeTrue();
});

it('closes an impersonation that has outlived its timeout', function (): void {
    $this->actingAs($this->admin, 'web')->post(
        route('admin.impersonation.start', $this->owner),
        ['reason' => 'Reproducing the scheduling bug from ticket 4471.'],
    );

    $record = ImpersonationSession::query()->latest('id')->first();

    // Push the session past the ceiling without waiting an hour.
    $record->forceFill([
        'started_at' => now()->subMinutes(ImpersonationSession::timeoutMinutes() + 1),
    ])->save();

    $this->get(route('agency.dashboard'))->assertRedirect(route('admin.dashboard'));

    expect($record->fresh()->isOpen())->toBeFalse()
        ->and(auth('web')->id())->toBe($this->admin->getKey());
});

it('closes an abandoned impersonation from the scheduler', function (): void {
    $record = ImpersonationSession::query()->forceCreate([
        'super_admin_user_id' => $this->admin->getKey(),
        'target_type' => User::class,
        'target_id' => $this->owner->getKey(),
        'tenant_id' => $this->tenant->getKey(),
        'reason' => 'Admin closed the tab without exiting.',
        'started_at' => now()->subMinutes(ImpersonationSession::timeoutMinutes() + 30),
    ]);

    $this->artisan('platform:heartbeat')->assertSuccessful();

    expect($record->fresh()->isOpen())->toBeFalse();
});

it('records the scheduler heartbeat that the dashboard reads', function (): void {
    cache()->forget((string) config('platform.health.cache_key'));

    $this->artisan('platform:heartbeat')->assertSuccessful();

    expect(cache()->get((string) config('platform.health.cache_key')))->not->toBeNull();

    $this->actingAs($this->admin, 'web')
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('Healthy');
});

it('says so loudly when the scheduler has never run', function (): void {
    cache()->forget((string) config('platform.health.cache_key'));

    $this->actingAs($this->admin, 'web')
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('Not running');
});

// ------------------------------------------------------- platform gates

it('never grants a platform permission to an ordinary agency user', function (): void {
    foreach (app(PermissionCatalogue::class)->platformPermissions() as $permission) {
        expect($this->owner->can($permission))->toBeFalse(
            "An agency owner holds [{$permission}].",
        );
        expect($this->admin->can($permission))->toBeTrue(
            "A Super Admin does not hold [{$permission}].",
        );
    }
});

it('does not let a super admin silently pass tenant policies', function (): void {
    /*
     | There is deliberately no Gate::before granting Super Admins everything.
     | If there were, a support engineer would satisfy checks written to protect
     | an agency's data without any audit trail -- which is what impersonation
     | exists to make visible.
     */
    $brand = Customer::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'Roast House',
        'slug' => 'roast-house',
        'status' => 'active',
        'timezone' => 'UTC',
    ]);

    expect($this->admin->can('view', $brand))->toBeFalse();
});

// --------------------------------------------------------- the way in

it('lets a super admin actually complete the login flow', function (): void {
    /*
     | The whole admin surface is unreachable if this path does not work, and
     | every other test here uses actingAs(), which skips it entirely. This is
     | the only test that goes through the real form.
     */
    $admin = superAdmin();
    $admin->forceFill(['password' => bcrypt('correct-horse-battery')])->save();

    $response = $this->post(route('login.store'), [
        'email' => $admin->email,
        'password' => 'correct-horse-battery',
    ]);

    // 2FA is confirmed, so Fortify must hand off to the challenge rather than
    // completing the session.
    $response->assertRedirect(route('two-factor.login'));
    expect(auth('web')->check())->toBeFalse('2FA was bypassed at login.');
    expect(session('login.id'))->toBe($admin->getKey());
});

it('completes the two-factor challenge and reaches the admin surface', function (): void {
    $admin = superAdmin();
    $admin->forceFill(['password' => bcrypt('correct-horse-battery')])->save();

    $this->post(route('login.store'), [
        'email' => $admin->email,
        'password' => 'correct-horse-battery',
    ])->assertRedirect(route('two-factor.login'));

    // A real TOTP for the seeded secret, so the challenge is exercised rather
    // than stubbed.
    $code = app(Google2FA::class)
        ->getCurrentOtp(decrypt($admin->two_factor_secret));

    $this->post(route('two-factor.login.store'), ['code' => $code])->assertRedirect();

    expect(auth('web')->id())->toBe($admin->getKey());

    $this->get('/admin')->assertOk();
});

it('does not strand a super admin on a workspace they do not belong to', function (): void {
    /*
     | Found in a browser. Fortify's `home` is one static path, so a Super Admin
     | -- who usually belongs to no agency at all -- completed login and landed
     | on /app, where ResolveTenant answered 403. Sign-in worked and went
     | nowhere; /admin was reachable only by typing the URL.
     */
    $admin = superAdmin();
    $admin->forceFill(['password' => bcrypt('correct-horse-battery')])->save();

    expect($admin->tenants()->count())->toBe(0);

    $this->post(route('login.store'), [
        'email' => $admin->email,
        'password' => 'correct-horse-battery',
    ])->assertRedirect(route('two-factor.login'));

    $code = app(Google2FA::class)
        ->getCurrentOtp(decrypt($admin->two_factor_secret));

    $this->post(route('two-factor.login.store'), ['code' => $code])
        ->assertRedirect(route('admin.dashboard', absolute: false));

    // And the destination actually renders, rather than being another dead end.
    $this->get(route('admin.dashboard'))->assertOk();
});

it('still sends an ordinary agency user to the agency dashboard', function (): void {
    $this->owner->forceFill(['password' => bcrypt('correct-horse-battery')])->save();

    $this->post(route('login.store'), [
        'email' => $this->owner->email,
        'password' => 'correct-horse-battery',
    ])->assertRedirect(route('agency.dashboard', absolute: false));
});

it('routes the root path by principal, the same way login does', function (): void {
    // The two must agree; landing somewhere different depending on which door
    // you came through is a support ticket.
    asAdmin($this->admin)->get('/')->assertRedirect(route('admin.dashboard', absolute: false));

    $this->actingAs($this->owner, 'web')
        ->withSession([config('tenancy.resolution.session_key', 'tenant_id') => $this->tenant->getKey()])
        ->get('/')
        ->assertRedirect(route('agency.dashboard', absolute: false));
});
