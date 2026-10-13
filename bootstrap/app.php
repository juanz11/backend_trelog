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
        // El gateway (nginx) corre en este mismo host y manda X-Forwarded-For /
        // X-Real-IP. Sin esto, `throttle` cuenta todas las peticiones publicas como
        // si vinieran de 127.0.0.1 y un cupo por IP seria un cupo global.
        $middleware->trustProxies(at: ['127.0.0.1', '::1']);

        $middleware->api(append: [
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);
        // Tres middlewares, y ninguno autentica (Lote 9): `gateway.auth` exige
        // que la peticion venga del gateway con identidad, `sso.role` exige un
        // rol `treslog:*` de los que el SSO emitio, `gateway.user` traduce la
        // identidad a la fila espejo de `users`. Los alias `driver` y `admin`
        // del camino viejo (Sanctum + role_user) murieron con ese camino.
        //
        // RequestContext no se agrega al grupo `api` a proposito: los grupos que
        // lo necesitan lo montan ellos, y los errores propios igual llevan
        // `request_id` (RequestContext::resolveFor() lo genera en el momento).
        $middleware->alias([
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
