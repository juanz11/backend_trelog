<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autoriza por rol usando los roles que el SSO resolvio y el gateway inyecto.
 *
 * Uso:  Route::middleware(['gateway.auth', 'sso.role:treslog:admin'])
 *
 * Debe ir SIEMPRE despues de gateway.auth, que es quien deja la identidad
 * disponible en el request.
 *
 * Copiado de MSH SIN CAMBIOS DE COMPORTAMIENTO, a proposito: es la pieza mas
 * generica de las tres y la que mas barato sale mantener sincronizada.
 *
 * NOTA (deuda conocida, no se toca en este lote): el 401 de abajo devuelve el
 * slug `unauthorized`, que igual que en AuthenticateFromGateway NO esta en el
 * catalogo cerrado de trece del contrato. La diferencia es que ESA rama solo se
 * alcanza con la cadena de middleware mal armada —un error de programacion, no
 * un caso del cliente— y por eso no se diverge aca: la correccion vale para las
 * dos aplicaciones a la vez y va con el reporte del bug a MSH, no en un parche
 * local que haga divergir el archivo para siempre.
 *
 * Los nombres de rol NUNCA se escriben sueltos en la ruta: salen de
 * config('sso.roles'), donde estan los valores con prefijo `treslog:`. Un rol
 * sin prefijo no viaja nunca y la falla es silenciosa.
 */
class RequireSsoRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $identity = $request->attributes->get('sso_user');

        if (! $identity) {
            return response()->json([
                // `unauthenticated` y NO `unauthorized`: es el slug del catalogo CERRADO
                // del SSO para 401. Esta rama solo se alcanza con la cadena mal armada
                // (sso.role antes que gateway.auth), pero el slug es el mismo igual.
                'error'   => 'unauthenticated',
                'message' => 'Falta gateway.auth antes de sso.role en la cadena de middleware.',
                'request_id' => RequestContext::resolveFor($request),
            ], 401);
        }

        $granted = array_map('strtolower', $identity['roles'] ?? []);
        $needed  = array_map('strtolower', $roles);

        if (empty(array_intersect($granted, $needed))) {
            return response()->json([
                'error'    => 'forbidden',
                'message'  => 'El usuario no tiene ninguno de los roles requeridos.',
                'required' => $roles,
                // Con request_id, como TODO error propio segun el contrato. Es el error
                // mas frecuente del camino nuevo y sin el id no hay forma de seguir la
                // peticion por los tres logs cuando la reportan. Lo marco la auditoria.
                'request_id' => RequestContext::resolveFor($request),
            ], 403);
        }

        return $next($request);
    }
}
