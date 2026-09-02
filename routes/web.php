<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\CreditController as AdminCreditController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\EntitlementOverrideController;
use App\Http\Controllers\Admin\FailedJobController;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\Admin\PlanController as AdminPlanController;
use App\Http\Controllers\Admin\TenantController as AdminTenantController;
use App\Http\Controllers\Agency\BillingController;
use App\Http\Controllers\Agency\BrandController;
use App\Http\Controllers\Agency\DashboardController;
use App\Http\Controllers\Agency\MediaController;
use App\Http\Controllers\Agency\PostController;
use App\Http\Controllers\Agency\SuspendedController;
use App\Http\Controllers\Agency\TeamController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\TwoFactorEnrolmentController;
use App\Http\Controllers\Webhook\RazorpayWebhookController;
use App\Support\HomeRedirector;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;

/*
 | The root path is a router, not a page. There is no marketing site in this
 | repository -- it is a separate concern -- and shipping Laravel's welcome
 | splash here is worse than sending people where they were going.
 */
Route::get('/', fn () => auth('web')->check()
    // Same rule as the login redirect, via the same class: a user who lands
    // somewhere different depending on which door they came through is a
    // support ticket.
    ? redirect(app(HomeRedirector::class)->pathFor(auth('web')->user()))
    : redirect()->route('login'))->name('home');

/*
|--------------------------------------------------------------------------
| Agency application
|--------------------------------------------------------------------------
|
| The `agency` middleware group is: auth:web -> verified -> tenant ->
| tenant.active. Order matters and is explained in bootstrap/app.php.
|
| Every route here is additionally gated by a policy or permission check
| inside its controller. A route-coverage test asserts that, so a new route
| cannot quietly ship without authorisation.
|
*/
Route::middleware('agency')->prefix('app')->name('agency.')->group(function (): void {

    Route::get('/', DashboardController::class)->name('dashboard');

    Route::get('brands', [BrandController::class, 'index'])->name('brands.index');
    Route::get('brands/create', [BrandController::class, 'create'])->name('brands.create');
    Route::post('brands', [BrandController::class, 'store'])->name('brands.store');
    Route::get('brands/{brand}', [BrandController::class, 'show'])->name('brands.show');
    Route::put('brands/{brand}', [BrandController::class, 'update'])->name('brands.update');
    Route::post('brands/{brand}/archive', [BrandController::class, 'archive'])->name('brands.archive');
    Route::post('brands/{brand}/unarchive', [BrandController::class, 'unarchive'])->name('brands.unarchive');

    Route::get('content', [PostController::class, 'index'])->name('posts.index');
    Route::get('content/create', [PostController::class, 'create'])->name('posts.create');
    Route::post('content', [PostController::class, 'store'])->name('posts.store');
    Route::get('content/{post}', [PostController::class, 'show'])->name('posts.show');
    Route::post('content/{post}/transition', [PostController::class, 'transition'])->name('posts.transition');

    Route::get('calendar', [PostController::class, 'calendar'])->name('calendar');

    Route::get('media', [MediaController::class, 'index'])->name('media.index');
    Route::post('media', [MediaController::class, 'store'])->name('media.store');

    Route::get('team', [TeamController::class, 'index'])->name('team.index');
    Route::post('team/invite', [TeamController::class, 'invite'])->name('team.invite');
});

/*
 | Billing sits OUTSIDE tenant.active on purpose: a suspended tenant must still
 | be able to reach the one screen that can restore their account. Locking
 | someone out of it turns a recoverable lapse into a cancellation.
 | See docs/03-TENANCY.md section 9.
 */
Route::middleware(['auth:web', 'verified', 'tenant'])
    ->prefix('app')
    ->name('agency.')
    ->group(function (): void {
        Route::get('billing', BillingController::class)->name('billing');
    });

/*
 | Where EnsureTenantActive sends a blocked tenant. Requires no permission:
 | billing itself is gated, so redirecting everyone there would bounce a member
 | without billing rights between a 403 and a redirect, with no way out.
 */
Route::middleware(['auth:web', 'verified', 'tenant'])
    ->get('app/paused', SuspendedController::class)
    ->name('billing.renew');

/*
|--------------------------------------------------------------------------
| Invitations
|--------------------------------------------------------------------------
|
| Authenticated but NOT tenant-scoped: the invitee is joining a tenant they do
| not yet belong to, so ResolveTenant would reject them before they could
| accept.
|
*/
Route::middleware(['auth:web'])->group(function (): void {
    Route::get('invitations/{token}', [InvitationController::class, 'show'])->name('invitations.accept');
    Route::post('invitations/{token}', [InvitationController::class, 'accept'])->name('invitations.store');
});

/*
|--------------------------------------------------------------------------
| Webhooks
|--------------------------------------------------------------------------
|
| Excluded from CSRF because the sender is a server, not a browser session.
| Protected instead by HMAC signature verification inside the controller, plus
| rate limiting here. The exclusion list is deliberately short and reviewed --
| see docs/10-SECURITY.md section 7.
|
*/
Route::post('/webhooks/razorpay', RazorpayWebhookController::class)
    ->middleware('throttle:100,1')
    ->withoutMiddleware([
        PreventRequestForgery::class,
    ])
    ->name('webhooks.razorpay');

/*
|--------------------------------------------------------------------------
| Two-factor enrolment
|--------------------------------------------------------------------------
|
| Fortify ships every 2FA endpoint but no screen to drive them. This is that
| screen, and it is behind `auth:web` ONLY -- deliberately not `super-admin`.
| EnsureSuperAdmin redirects here when an administrator has not enrolled, so
| gating it on the same check would be a redirect loop.
|
*/
Route::middleware(['auth:web'])
    ->get('user/two-factor', TwoFactorEnrolmentController::class)
    ->name('two-factor.enrol');

/*
|--------------------------------------------------------------------------
| Super Admin
|--------------------------------------------------------------------------
|
| The one surface where tenant global scopes are deliberately bypassed. Three
| independent gates apply:
|
|   1. `admin` middleware  -- auth:web + EnsureSuperAdmin (which also demands
|                             confirmed 2FA, and answers 404 rather than 403 so
|                             the surface is not discoverable)
|   2. a platform gate     -- checked inside every action, so a future split
|                             into narrower staff roles is a config change
|   3. an audit entry      -- written by the services, never by the controller
|
| See docs/01-ARCHITECTURE.md section 8 and docs/04-AUTH-RBAC.md section 9.
|
*/
Route::middleware('admin')->prefix('admin')->name('admin.')->group(function (): void {

    Route::get('/', AdminDashboardController::class)->name('dashboard');

    Route::get('agencies', [AdminTenantController::class, 'index'])->name('tenants.index');
    Route::get('agencies/create', [AdminTenantController::class, 'create'])->name('tenants.create');
    Route::post('agencies', [AdminTenantController::class, 'store'])->name('tenants.store');
    Route::get('agencies/{tenant}', [AdminTenantController::class, 'show'])->name('tenants.show');

    Route::post('agencies/{tenant}/suspend', [AdminTenantController::class, 'suspend'])->name('tenants.suspend');
    Route::post('agencies/{tenant}/reactivate', [AdminTenantController::class, 'reactivate'])->name('tenants.reactivate');

    Route::post('agencies/{tenant}/entitlements', [EntitlementOverrideController::class, 'store'])
        ->name('tenants.entitlements.store');
    Route::delete('agencies/{tenant}/entitlements/{key}', [EntitlementOverrideController::class, 'destroy'])
        ->name('tenants.entitlements.destroy');

    Route::post('agencies/{tenant}/credits', [AdminCreditController::class, 'store'])->name('tenants.credits.store');

    Route::post('impersonate/{user}', [ImpersonationController::class, 'store'])->name('impersonation.start');

    Route::get('plans', [AdminPlanController::class, 'index'])->name('plans.index');
    Route::get('audit', [AuditLogController::class, 'index'])->name('audit.index');

    Route::get('jobs', [FailedJobController::class, 'index'])->name('jobs.index');
    Route::post('jobs/{uuid}/retry', [FailedJobController::class, 'retry'])->name('jobs.retry');
});

/*
 | Leaving impersonation sits OUTSIDE the admin group on purpose.
 |
 | While impersonating, the session's principal is the customer -- who is not a
 | Super Admin. Putting this behind EnsureSuperAdmin would 404 the only way out
 | and trap the administrator inside the account they were supporting.
 */
Route::middleware(['auth:web'])
    ->delete('admin/impersonate', [ImpersonationController::class, 'destroy'])
    ->name('admin.impersonation.stop');
