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
use App\Http\Controllers\Agency\AiController;
use App\Http\Controllers\Agency\AnalyticsController;
use App\Http\Controllers\Agency\BillingController;
use App\Http\Controllers\Agency\BrandBrainController;
use App\Http\Controllers\Agency\BrandController;
use App\Http\Controllers\Agency\DashboardController;
use App\Http\Controllers\Agency\MediaController;
use App\Http\Controllers\Agency\MediaFileController;
use App\Http\Controllers\Agency\NotificationController;
use App\Http\Controllers\Agency\NotificationSettingsController;
use App\Http\Controllers\Agency\OAuthController;
use App\Http\Controllers\Agency\PostCommentController;
use App\Http\Controllers\Agency\PostController;
use App\Http\Controllers\Agency\SessionController;
use App\Http\Controllers\Agency\SettingsController;
use App\Http\Controllers\Agency\SocialAccountController;
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
    /*
     | The brand profile every AI feature is grounded in. Gated on
     | ai.manage_brand_brain rather than customers.update: editing this changes
     | what the AI says on a client's behalf across every future post.
     */
    Route::get('brands/{brand}/brain', [BrandBrainController::class, 'edit'])->name('brands.brain');
    Route::put('brands/{brand}/brain', [BrandBrainController::class, 'update'])->name('brands.brain.update');

    Route::post('brands/{brand}/archive', [BrandController::class, 'archive'])->name('brands.archive');
    Route::post('brands/{brand}/unarchive', [BrandController::class, 'unarchive'])->name('brands.unarchive');

    Route::get('content', [PostController::class, 'index'])->name('posts.index');
    Route::get('content/create', [PostController::class, 'create'])->name('posts.create');
    Route::post('content', [PostController::class, 'store'])->name('posts.store');
    Route::get('content/{post}', [PostController::class, 'show'])->name('posts.show');
    Route::post('content/{post}/transition', [PostController::class, 'transition'])->name('posts.transition');

    /*
     | The agency half of the conversation. PostComment carries is_internal for
     | exactly this, and only the client could reach it: a client could comment
     | on work awaiting their approval and nobody at the agency would see it.
     */
    Route::post('content/{post}/comments', [PostCommentController::class, 'store'])
        ->name('posts.comment');

    Route::get('calendar', [PostController::class, 'calendar'])->name('calendar');

    /*
     | A user's own notifications. No permission gate: the relation scopes them
     | to the signed-in identity, which is the only boundary that applies.
     */
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::post('notifications/{id}/read', [NotificationController::class, 'read'])->name('notifications.read');

    Route::get('notifications/settings', [NotificationSettingsController::class, 'edit'])
        ->name('notifications.settings');
    Route::put('notifications/settings', [NotificationSettingsController::class, 'update'])
        ->name('notifications.settings.update');

    Route::get('media', [MediaController::class, 'index'])->name('media.index');
    Route::post('media', [MediaController::class, 'store'])->name('media.store');
    Route::put('media/{media}', [MediaController::class, 'update'])->name('media.update');

    /*
     | Streams one file after MediaPolicy@download. `signed` on top of the
     | agency group's auth: the signature stops ids being walked, and the
     | session stops a signed URL being useful to anyone it is forwarded to.
     |
     | A separate route from the portal's because the two surfaces authorise
     | completely differently. One route branching on the guard is how the
     | looser branch eventually answers for the stricter one.
     */
    Route::get('media/{media}/file', MediaFileController::class)
        ->middleware('signed')
        ->name('media.file');

    /*
     | The AI studio. Twelve features, the credit ledger, reservations and the
     | Brand Brain context builder were all built and tested with no route
     | reaching any of them, so `ai.use` governed nothing and a tenant's
     | monthly credits could only be spent from a test.
     */
    /*
     | What the work achieved. `analytics.view` has been in the permission
     | catalogue since Step 5, and the Analyst role has existed to hold it,
     | governing nothing: no table, no collection, no screen.
     */
    Route::get('analytics', [AnalyticsController::class, 'index'])->name('analytics.index');

    Route::get('ai', [AiController::class, 'index'])->name('ai.index');
    Route::get('ai/{feature}', [AiController::class, 'show'])->name('ai.show');
    Route::post('ai/{feature}', [AiController::class, 'generate'])->name('ai.generate');

    /*
     | Connecting social accounts. OAuthStateService, the provider contract and
     | every adapter capability existed with no route to reach them, so the only
     | way to get a publishable destination was to seed one into the database.
     |
     | The two legs are deliberately different shapes. Connecting is a GET that
     | leaves the site; choosing destinations is a POST that writes rows, and
     | the connection is bound by id so another agency's grant 404s on the
     | tenant scope rather than being authorised later.
     */
    Route::get('social', [SocialAccountController::class, 'index'])->name('social.index');

    Route::get('social/connect/{provider}', [OAuthController::class, 'redirect'])
        ->name('social.connect');

    Route::get('social/connections/{connection}', [SocialAccountController::class, 'choose'])
        ->name('social.choose');

    Route::post('social/connections/{connection}', [SocialAccountController::class, 'store'])
        ->name('social.store');

    Route::delete('social/{account}', [SocialAccountController::class, 'destroy'])
        ->name('social.destroy');

    /*
     | The signed-in user's own devices. sessions.guard was added for this in
     | the first migration and stayed null until a guard-aware handler wrote it.
     */
    Route::get('sessions', [SessionController::class, 'index'])->name('sessions.index');
    Route::delete('sessions/others', [SessionController::class, 'destroyOthers'])
        ->name('sessions.destroy-others');
    Route::delete('sessions/{session}', [SessionController::class, 'destroy'])
        ->name('sessions.destroy');

    /*
     | Workspace settings. settings.view and settings.update were in the
     | permission catalogue from Step 5 and governed nothing until now, so an
     | agency that signed up with the wrong timezone had no way to correct it.
     */
    Route::get('settings', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');

    Route::get('team', [TeamController::class, 'index'])->name('team.index');
    Route::post('team/invite', [TeamController::class, 'invite'])->name('team.invite');

    /*
     | Until these existed an agency could invite people and never remove them:
     | MembershipStatus::Suspended was defined and enforced, and nothing could
     | set it. Suspension lands on the member's next request, because
     | ResolveTenant re-reads the membership every time.
     */
    Route::post('team/{member}/suspend', [TeamController::class, 'suspend'])
        ->name('team.suspend');
    Route::post('team/{member}/reinstate', [TeamController::class, 'reinstate'])
        ->name('team.reinstate');
    Route::put('team/{member}/role', [TeamController::class, 'updateRole'])
        ->name('team.role');
    Route::post('team/invitations/{invitation}/revoke', [TeamController::class, 'revokeInvitation'])
        ->name('team.invitation.revoke');
});

/*
 | Billing sits OUTSIDE tenant.active on purpose: a suspended tenant must still
 | be able to reach the one screen that can restore their account. Locking
 | someone out of it turns a recoverable lapse into a cancellation.
 | See docs/03-TENANCY.md section 9.
 */
/*
| The OAuth callback, and the one route whose PATH is not ours to choose.
|
| config('social.oauth.redirect_path') is what OAuthStateService sends to the
| provider as redirect_uri, and providers exact-match it against what was
| registered in their developer console. Mounting this under the /app prefix
| with the rest of the surface would send every provider to a 404.
|
| Still behind the full agency stack. The state is bound to a user and consumed
| once, but authentication is what makes that binding checkable at all -- and a
| callback URL that answers to anonymous requests is a thing worth not having.
|
| The session survives the round trip because SESSION_SAME_SITE is lax, which
| sends cookies on a top-level GET navigation like this one. Setting it to
| strict would sign the user out on the way back.
*/
Route::middleware('agency')
    ->get('oauth/{provider}/callback', [OAuthController::class, 'callback'])
    ->name('agency.social.callback');

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
