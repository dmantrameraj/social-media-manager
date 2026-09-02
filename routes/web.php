<?php

declare(strict_types=1);

use App\Http\Controllers\Agency\BillingController;
use App\Http\Controllers\Agency\BrandController;
use App\Http\Controllers\Agency\DashboardController;
use App\Http\Controllers\Agency\MediaController;
use App\Http\Controllers\Agency\PostController;
use App\Http\Controllers\Agency\SuspendedController;
use App\Http\Controllers\Agency\TeamController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\Webhook\RazorpayWebhookController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;

/*
 | The root path is a router, not a page. There is no marketing site in this
 | repository -- it is a separate concern -- and shipping Laravel's welcome
 | splash here is worse than sending people where they were going.
 */
Route::get('/', fn () => redirect()->route(
    auth('web')->check() ? 'agency.dashboard' : 'login',
))->name('home');

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
