<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsureTenantActive;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'tenant.active' => EnsureTenantActive::class,
            'super-admin' => EnsureSuperAdmin::class,
        ]);

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
