<?php

use App\Http\Middleware\EnsureEmailIsVerified;
use App\Http\Middleware\EnsureSuperadmin;
use App\Http\Middleware\ResolveCurrentOrganization;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Widget público: grupo propio SIN el middleware «api» (que añadiría la sesión de Sanctum).
            Route::middleware(SubstituteBindings::class)
                ->prefix('api/widget')
                ->group(base_path('routes/widget.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            EnsureFrontendRequestsAreStateful::class,
        ]);

        $middleware->alias([
            'verified' => EnsureEmailIsVerified::class,
            'organization' => ResolveCurrentOrganization::class,
            'superadmin' => EnsureSuperadmin::class,
        ]);

        // La empresa actual debe estar fijada ANTES del route model binding: los
        // modelos de negocio se resuelven con el global scope del tenant.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: ResolveCurrentOrganization::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
