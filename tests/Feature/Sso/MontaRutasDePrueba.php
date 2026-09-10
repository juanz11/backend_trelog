<?php

namespace Tests\Feature\Sso;

use App\Http\Middleware\RequestContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * Monta rutas efimeras para ejercitar los middlewares del camino SSO.
 *
 * POR QUE HACE FALTA ESTO:
 * en el Lote 3 los middlewares son CODIGO INERTE — estan registrados como alias
 * pero no cuelgan de ninguna ruta de produccion, y no deben colgar: el bloque
 * `auth:sanctum` es el unico camino vivo hasta el Lote 9. Un middleware sin ruta
 * no se puede probar con una peticion, y probarlo llamando a `handle()` a mano
 * saltearia justamente lo que importa: el orden de la cadena, el render del
 * JSON, el status code real.
 *
 * Estas rutas viven y mueren dentro del test. No tocan routes/api.php.
 */
trait MontaRutasDePrueba
{
    /**
     * Registra una ruta bajo `/api/treslog/_prueba/...` con la cadena indicada.
     *
     * El handler devuelve lo que el middleware dejo en el request, que es lo
     * unico que un test de middleware tiene que mirar.
     */
    protected function montarRuta(string $uri, array $middleware, ?callable $handler = null): void
    {
        $handler ??= fn (Request $request) => response()->json([
            'sso_user'   => $request->attributes->get('sso_user'),
            'request_id' => $request->attributes->get(RequestContext::ATTRIBUTE),
            'user_id'    => $request->user()?->id,
            'email'      => $request->user()?->email,
            'name'       => $request->user()?->name,
        ]);

        Route::middleware($middleware)->get($uri, $handler);
    }

    /** Cabeceras de una peticion que SI paso por el gateway. */
    protected function cabecerasDelGateway(array $extra = []): array
    {
        return array_merge([
            'X-Auth-Gateway'  => 'myglobalhub-gateway',
            'X-User-Id'       => '4242',
            'X-User-Clerk-Id' => 'user_2abcClerk',
            'X-User-Email'    => 'conductor@tr3slog.test',
            'X-User-Name'     => 'Ana Conductora',
            'X-User-Roles'    => 'treslog:driver',
        ], $extra);
    }
}
