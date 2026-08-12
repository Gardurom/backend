<?php

use App\Http\Middleware\RequireCampusContext;
use App\Http\Middleware\RequirePermission;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(
    basePath: dirname(__DIR__)
)
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(
        function (Middleware $middleware): void {
            /*
             * Permite autenticacion SPA mediante cookies de sesion
             * con Laravel Sanctum.
             *
             * No se debe agregar el middleware "web" manualmente
             * a las rutas API stateful.
             */
            $middleware->statefulApi();

            $middleware->alias([
                'permission' => RequirePermission::class,
                'campus' => RequireCampusContext::class,
            ]);
        }
    )
    ->withExceptions(
        function (Exceptions $exceptions): void {
            //
        }
    )
    ->create();