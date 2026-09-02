<?php

declare(strict_types=1);

use App\Domain\Billing\Entitlements\EntitlementResolver;
use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\CustomerPortalUser;
use App\Domain\Identity\Models\User;
use App\Domain\Publishing\Enums\PostStatus;
use App\Domain\Publishing\Models\Post;
use App\Domain\Social\Models\SocialAccount;
use App\Domain\Tenancy\Enums\MembershipStatus;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Services\ProvisionTenantService;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    seedPermissions();

    $this->owner = User::factory()->create();
    $this->tenant = app(ProvisionTenantService::class)->execute($this->owner, 'Bright Digital');
    $this->owner = $this->owner->fresh();

    givePlanLimit($this->tenant->getKey(), 'brands.max', 25);

    actingForTenant($this->tenant);
    $this->brand = Customer::factory()->create([
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'Roast House',
    ]);
});

/** Sign in as a user, with the workspace already selected. */
function asAgencyUser(User $user)
{
    return test()->actingAs($user, 'web')
        ->withSession([
            config('tenancy.resolution.session_key', 'tenant_id') => test()->tenant->getKey(),
        ]);
}

function memberWith(string $role): User
{
    $user = User::factory()->create();

    $user->tenants()->attach(test()->tenant->getKey(), [
        'status' => MembershipStatus::Active->value,
        'joined_at' => now(),
    ]);

    $registrar = app(PermissionRegistrar::class);
    $previous = $registrar->getPermissionsTeamId();
    $registrar->setPermissionsTeamId(test()->tenant->getKey());

    try {
        $user->assignRole($role);
    } finally {
        $registrar->setPermissionsTeamId($previous);
    }

    return $user->fresh();
}

// ------------------------------------------------------------------- rendering

it('renders every main agency screen for an owner', function (string $route): void {
    asAgencyUser($this->owner)->get(route($route))->assertOk();
})->with([
    'agency.dashboard',
    'agency.brands.index',
    'agency.brands.create',
    'agency.calendar',
    'agency.posts.index',
    'agency.posts.create',
    'agency.media.index',
    'agency.team.index',
    'agency.billing',
]);

it('renders a brand and a post', function (): void {
    asAgencyUser($this->owner)->get(route('agency.brands.show', $this->brand))->assertOk();

    $post = Post::factory()->forCustomer($this->brand)->create();

    asAgencyUser($this->owner)->get(route('agency.posts.show', $post))->assertOk();
});

// ------------------------------------------------------------ auth and tenancy

it('sends a guest to sign in', function (): void {
    $this->get(route('agency.dashboard'))->assertRedirect(route('login'));
});

it('refuses a user with no membership in the selected workspace', function (): void {
    $outsider = User::factory()->create();

    // ResolveTenant re-reads membership on every request; a session value is
    // never trusted on its own.
    asAgencyUser($outsider)->get(route('agency.dashboard'))->assertForbidden();
});

it('keeps a portal user out of the agency application entirely', function (): void {
    $portalUser = CustomerPortalUser::factory()->forTenant($this->tenant)->create();

    $this->actingAs($portalUser, 'customer')
        ->get(route('agency.dashboard'))
        ->assertRedirect(route('login'));
});

it('shows a suspended tenant the billing screen but not the product', function (): void {
    $this->tenant->forceFill(['status' => 'suspended'])->save();

    asAgencyUser($this->owner)->get(route('agency.dashboard'))
        ->assertRedirect(route('billing.renew'));

    // Billing stays reachable: locking someone out of the only screen that can
    // restore their account turns a lapse into a cancellation.
    asAgencyUser($this->owner)->get(route('agency.billing'))->assertOk();
});

// ------------------------------------------------------------- authorisation

it('hides screens a role cannot use', function (): void {
    $designer = memberWith('Designer');

    // Designer has media and customers.view, but no team or billing rights.
    asAgencyUser($designer)->get(route('agency.media.index'))->assertOk();
    asAgencyUser($designer)->get(route('agency.team.index'))->assertForbidden();
    asAgencyUser($designer)->get(route('agency.billing'))->assertForbidden();
});

it('refuses to create a brand without the permission', function (): void {
    $creator = memberWith('Content Creator');

    asAgencyUser($creator)->get(route('agency.brands.create'))->assertForbidden();

    asAgencyUser($creator)->post(route('agency.brands.store'), ['name' => 'Sneaky Brand'])
        ->assertForbidden();

    expect(Customer::query()->where('name', 'Sneaky Brand')->exists())->toBeFalse();
});

it('hides a brand the user is not assigned to', function (): void {
    $creator = memberWith('Content Creator');

    // Content Creator lacks customers.view_all, so an unassigned brand is
    // neither listed nor reachable.
    asAgencyUser($creator)->get(route('agency.brands.show', $this->brand))->assertForbidden();

    $this->brand->users()->attach($creator->getKey());
    $creator->forgetAssignedCustomers();

    asAgencyUser($creator->fresh())->get(route('agency.brands.show', $this->brand))->assertOk();
});

it('returns 404 for a brand in another tenant', function (): void {
    $otherTenant = app(ProvisionTenantService::class)
        ->execute(User::factory()->create(), 'Other Agency');

    withoutTenantContext();
    $foreignBrand = Customer::factory()->create(['tenant_id' => $otherTenant->getKey()]);

    // The global scope makes route-model binding miss entirely, so this is a
    // 404 rather than a 403 -- the brand's existence is not confirmed.
    asAgencyUser($this->owner)->get(route('agency.brands.show', $foreignBrand))
        ->assertNotFound();
});

// -------------------------------------------------------------------- writing

it('creates a brand', function (): void {
    asAgencyUser($this->owner)
        ->post(route('agency.brands.store'), [
            'name' => 'ABC Restaurant',
            'timezone' => 'Asia/Kolkata',
        ])
        ->assertRedirect();

    expect(Customer::query()->where('name', 'ABC Restaurant')->exists())->toBeTrue();
});

it('reports a plan limit rather than failing with a 500', function (): void {
    givePlanLimit($this->tenant->getKey(), 'brands.max', 1);
    app(EntitlementResolver::class)->forget($this->tenant);

    asAgencyUser($this->owner)
        ->post(route('agency.brands.store'), ['name' => 'One Too Many'])
        ->assertRedirect()
        ->assertSessionHas('error');
});

it('creates a post with a target for each selected account', function (): void {
    $account = SocialAccount::factory()->forCustomer($this->brand)->create();

    asAgencyUser($this->owner)
        ->post(route('agency.posts.store'), [
            'customer_id' => $this->brand->getKey(),
            'body' => 'Freshly roasted every morning.',
            'accounts' => [$account->getKey()],
        ])
        ->assertRedirect();

    $post = Post::query()->firstOrFail();

    expect($post->status)->toBe(PostStatus::Draft)
        ->and($post->targets)->toHaveCount(1)
        ->and($post->timezone)->toBe($this->brand->timezone);
});

it('ignores a social account belonging to another brand', function (): void {
    $otherBrand = Customer::factory()->create(['tenant_id' => $this->tenant->getKey()]);
    $foreignAccount = SocialAccount::factory()->forCustomer($otherBrand)->create();

    asAgencyUser($this->owner)
        ->post(route('agency.posts.store'), [
            'customer_id' => $this->brand->getKey(),
            'body' => 'Copy.',
            'accounts' => [$foreignAccount->getKey()],
        ])
        ->assertRedirect();

    // Ids arrive from a form, and the global scope cannot see intent.
    expect(Post::query()->firstOrFail()->targets)->toHaveCount(0);
});

it('rejects a schedule in the past', function (): void {
    asAgencyUser($this->owner)
        ->post(route('agency.posts.store'), [
            'customer_id' => $this->brand->getKey(),
            'body' => 'Copy.',
            'scheduled_at' => now()->subDay()->format('Y-m-d\TH:i'),
        ])
        ->assertSessionHasErrors('scheduled_at');
});

it('moves a post through a legal transition and records it', function (): void {
    $post = Post::factory()->forCustomer($this->brand)->create();

    asAgencyUser($this->owner)
        ->post(route('agency.posts.transition', $post), [
            'status' => PostStatus::InternalReview->value,
            'comment' => 'Ready for review.',
        ])
        ->assertRedirect();

    expect($post->fresh()->status)->toBe(PostStatus::InternalReview)
        ->and($post->approvals()->count())->toBe(1);
});

it('refuses an illegal transition with a message rather than a crash', function (): void {
    $post = Post::factory()->forCustomer($this->brand)->create();

    asAgencyUser($this->owner)
        ->post(route('agency.posts.transition', $post), [
            'status' => PostStatus::Published->value,
        ])
        ->assertRedirect()
        ->assertSessionHas('error');

    expect($post->fresh()->status)->toBe(PostStatus::Draft);
});

/*
|--------------------------------------------------------------------------
| Route coverage -- a Phase 1 exit criterion
|--------------------------------------------------------------------------
*/

it('gates every agency route behind authentication and tenancy', function (): void {
    $router = app('router');

    // gatherMiddleware() returns GROUP NAMES ('web', 'agency'), not their
    // members, so the groups have to be expanded before asserting.
    $expand = function (array $middleware) use ($router): array {
        $groups = $router->getMiddlewareGroups();
        $flat = [];

        foreach ($middleware as $item) {
            foreach ($groups[$item] ?? [$item] as $inner) {
                $flat[] = is_string($inner) ? $inner : (string) $inner;
            }
        }

        return $flat;
    };

    $routes = collect($router->getRoutes())
        ->filter(fn ($route): bool => str_starts_with((string) $route->getName(), 'agency.'));

    expect($routes)->not->toBeEmpty();

    foreach ($routes as $route) {
        $middleware = $expand($route->gatherMiddleware());
        $name = $route->getName();

        // in_array rather than toContain(): Pest treats extra toContain()
        // arguments as further expected values, not as a failure message.
        expect(in_array('auth:web', $middleware, true))
            ->toBeTrue("[{$name}] is not behind auth:web.");

        expect(in_array('tenant', $middleware, true))
            ->toBeTrue("[{$name}] does not resolve a tenant.");
    }
});

it('resolves the tenant before route-model binding', function (): void {
    $priority = (function () {
        $kernel = app(Kernel::class);
        $property = (new ReflectionClass($kernel))->getProperty('middlewarePriority');
        $property->setAccessible(true);

        return $property->getValue($kernel);
    })();

    $tenantAt = array_search(ResolveTenant::class, $priority, true);
    $bindingAt = array_search(SubstituteBindings::class, $priority, true);

    // If binding ran first, another tenant's record would be found and the
    // request would reach the policy -- answering 403 and thereby confirming
    // the record exists.
    expect($tenantAt)->not->toBeFalse()
        ->and($bindingAt)->not->toBeFalse()
        ->and($tenantAt)->toBeLessThan($bindingAt);
});

it('authorises inside every agency controller action', function (): void {
    /*
     | Every agency route must perform an authorisation check in its handler.
     | Middleware proves WHO is asking and WHICH tenant; only the controller
     | can decide whether this user may do this thing to this record.
     |
     | Asserted by reading the source, so a new route cannot quietly ship
     | without one -- which is exactly how authorisation gaps appear.
     */
    $exempt = [
        // Aggregates already filtered to the caller's own tenant and brands.
        'agency.dashboard',
    ];

    $missing = [];

    foreach (app('router')->getRoutes() as $route) {
        $name = (string) $route->getName();

        if (! str_starts_with($name, 'agency.') || in_array($name, $exempt, true)) {
            continue;
        }

        [$class, $method] = array_pad(explode('@', $route->getActionName()), 2, '__invoke');

        $source = file_get_contents((new ReflectionClass($class))->getFileName()) ?: '';

        $reflection = new ReflectionMethod($class, $method);
        $body = implode("\n", array_slice(
            explode("\n", $source),
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));

        $authorises = str_contains($body, '->can(')
            || str_contains($body, 'authorize(')
            || str_contains($body, 'assertReachable');

        if (! $authorises) {
            $missing[] = $name;
        }
    }

    expect($missing)->toBeEmpty(
        'These agency routes perform no authorisation check: '.implode(', ', $missing)
    );
});

// ------------------------------------------------------- landing and redirects

/*
 | These three shipped broken and the suite did not notice, because every test
 | asserted a redirect happened without asserting the destination was a real
 | page. A browser walk-through found all of them in under a minute.
 */

it('sends a signed-in user to a route that exists', function (): void {
    // Fortify's default home is '/home', which this application does not
    // define: every successful login landed on a 404.
    $home = (string) config('fortify.home');

    expect($home)->not->toBe('/home');

    $response = asAgencyUser($this->owner)->get($home);

    expect($response->status())->toBeLessThan(400, "Login lands on [{$home}], which does not render.");
});

it('routes the root path instead of serving a marketing splash', function (): void {
    $this->get('/')->assertRedirect(route('login'));

    asAgencyUser($this->owner)->get('/')->assertRedirect(route('agency.dashboard'));
});

it('does not tell a healthy tenant that its workspace is paused', function (): void {
    // /app/paused sits outside the tenant.active group -- it has to, or a
    // blocked tenant could not reach it -- so it answers for everyone. It used
    // to render unconditionally and told a trialing workspace that publishing
    // was unavailable.
    expect($this->tenant->permitsProductAccess())->toBeTrue();

    asAgencyUser($this->owner)
        ->get(route('billing.renew'))
        ->assertRedirect(route('agency.dashboard'));
});

it('still explains the block to a tenant that is genuinely suspended', function (): void {
    $this->tenant->forceFill(['status' => TenantStatus::Suspended])->save();

    asAgencyUser($this->owner->fresh())
        ->get(route('billing.renew'))
        ->assertOk()
        ->assertSee('This workspace is paused');
});
