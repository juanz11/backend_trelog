<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(append: [
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);
        // Lote 4: `gateway.auth` y `sso.role` DEJARON DE SER INERTES. Cuelgan del
        // bloque `Route::prefix('treslog')` de routes/api.php, sobre la superficie
        // de administracion (routes/admin-only.php) y nada mas. `gateway.user`
        // sigue sin montarse: ese grupo no toca `$request->user()`, y su montaje
        // real es del Lote 5 (conductor).
        //
        // Lo que NO cambio: el bloque `auth:sanctum` sigue vivo, con las mismas
        // URLs. Los dos caminos conviven hasta el Lote 9, que es el unico punto
        // de no retorno del plan. Hay conductores con la app instalada en la
        // calle y clientes web sin migrar; el dia que el camino nuevo reemplace
        // al viejo, esa gente se queda sin backend.
        //
        // RequestContext SIGUE sin agregarse al grupo `api`. Prependerlo cambia
        // el comportamiento de TODAS las rutas vivas (logging por peticion y
        // normalizacion de X-Request-Id), y este lote toca cuatro grupos, no
        // todos. Va con el Lote 5, que es cuando entra trafico real por el
        // gateway. Mientras tanto los errores propios igual llevan `request_id`:
        // RequestContext::resolveFor() lo genera en el momento.
        $middleware->alias([
            'driver'       => \App\Http\Middleware\EnsureUserIsDriver::class,

            // Guarda de admin del camino VIEJO. Existia en el repo desde antes
            // (app/Http/Middleware/IsAdmin.php) sin estar registrada en ningun
            // lado ni usada por ninguna ruta: codigo muerto. Se registra aca
            // porque es la unica forma de cerrar HOY el agujero 2 de
            // `1-proposal.md §1` sin depender del gateway, que todavia no esta
            // desplegado. Muere en el Lote 9 junto con `role_user`.
            'admin'        => \App\Http\Middleware\IsAdmin::class,

            'gateway.auth' => \App\Http\Middleware\AuthenticateFromGateway::class,
            'gateway.user' => \App\Http\Middleware\ResolveDomainUser::class,
            'sso.role'     => \App\Http\Middleware\RequireSsoRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
