<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsureTenantActive;
use App\Http\Middleware\HandleImpersonation;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            /*
             | The portal has its own route file, loaded with the `web`
             | middleware (session and CSRF) but nothing else. Keeping it out of
             | web.php is the point: the client surface shares no route group,
             | no controller and no layout namespace with the agency one.
             */
            Route::middleware('web')->group(base_path('routes/portal.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'tenant.active' => EnsureTenantActive::class,
            'super-admin' => EnsureSuperAdmin::class,
        ]);

        /*
         | ResolveTenant MUST run before SubstituteBindings.
         |
         | Route-model binding queries the model, and the tenant global scope
         | only applies once context exists. In the default order, binding runs
         | first: another tenant's record is found, and the request survives to
         | the policy check. That still denies access, but it answers 403 rather
         | than 404 -- confirming the record exists.
         |
         | With this ordering the scope filters it out during binding, so a
         | foreign record is indistinguishable from a missing one.
         */
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: ResolveTenant::class,
        );

        /*
         | The agency surface. Order matters:
         |   auth      -- establishes the principal
         |   verified  -- before any tenant work, so unverified users cannot act
         |   tenant    -- resolves context and binds the spatie team id
         |   active    -- gates on lifecycle, and needs context to exist first
         */
        $middleware->group('agency', [
            'auth:web',
            'verified',
            'tenant',
            'tenant.active',
        ]);

        // The client portal shares nothing with the agency stack -- different
        // guard, different layout namespace, no tenant middleware, because a
        // portal user's access is brand-scoped rather than tenant-scoped.
        $middleware->group('portal', [
            'auth:customer',
        ]);

        /*
         | Impersonation limits apply to the WHOLE web group, not only to the
         | agency surface. A restriction that covers just the routes someone
         | remembered to protect is not a restriction, and the session timeout
         | has to hold on every route -- including ones added later by someone
         | who has never read HandleImpersonation.
         |
         | Appended rather than prepended: it needs an established session and
         | a resolved route name, so it must run after StartSession and after
         | SubstituteBindings.
         */
        $middleware->web(append: [
            HandleImpersonation::class,
        ]);

        /*
         | Send an unauthenticated visitor to the login screen for the surface
         | they were actually trying to reach.
         |
         | Laravel's default points everything at route('login'), which is the
         | AGENCY sign-in. A client whose session expired while reviewing content
         | landed there, typed their portal credentials into a form backed by a
         | different guard and a different table, and was told the credentials do
         | not match -- a dead end for the audience least equipped to work out
         | why. Found by a test asserting the redirect target rather than merely
         | that a redirect happened.
         */
        $middleware->redirectGuestsTo(fn (Request $request): string => $request->is('portal', 'portal/*')
            ? route('portal.login')
            : route('login'));

        $middleware->group('admin', [
            'auth:web',
            'super-admin',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
