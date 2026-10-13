<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resuelve la identidad del usuario a partir de las cabeceras que inyecta el
 * API Gateway del SSO.
 *
 * TR3SLOG no valida tokens ni mantiene credenciales por este camino: el gateway
 * ya valido el Bearer contra Passport y sobreescribio las cabeceras X-User-*,
 * de modo que un cliente que pase POR EL GATEWAY no puede falsificarlas.
 *
 * IMPORTANTE: el control de seguridad real es de red. Este backend NO debe
 * publicar su puerto al host; solo el gateway debe poder alcanzarlo. Si se
 * expone directamente, cualquiera puede enviar X-User-Id y saltearse el SSO.
 * Hoy ese control NO se cumple durante la transicion, y esta documentado en
 * tests/Feature/Sso/SuperficieAbiertaSelloForjableTest.php — leelo antes de
 * confiar en el parrafo de arriba.
 *
 * Copiado de MSH (app/Http/Middleware/AuthenticateFromGateway.php) con DOS
 * divergencias, marcadas abajo y justificadas en 3-design.md §C.2. Todo lo
 * demas es identico a proposito: es el contrato compartido entre aplicaciones.
 */
class AuthenticateFromGateway
{
    public function handle(Request $request, Closure $next): Response
    {
        $headers = config('sso.headers');

        // -- DIVERGENCIA 2 (a): el sello se comprueba PRIMERO ----------------
        // MSH mira la identidad antes que la firma. El orden importa para el
        // diagnostico, que es literalmente lo que pide la spec en el escenario
        // "Falta X-User-Id pese al sello de gateway": con el sello adelante hay
        // dos causas SEPARABLES —"esto no vino del gateway" y "vino del gateway
        // pero el gateway esta mal configurado"—. Con el orden de MSH, una
        // peticion directa con un X-User-Id forjado y sin sello se loguea con
        // el motivo equivocado, y alguien va a pasar una tarde revisando NGINX.
        //
        // -- DIVERGENCIA 2 (b): sin escape por config vacia -------------------
        // MSH solo comprueba la firma `if (filled($expectedSignature))`, con el
        // valor leido de env(). Eso convierte un MUST de la spec en algo que se
        // apaga con un `.env`: el test pasa en CI y miente en el ambiente donde
        // la variable quedo vacia. Aca la firma es constante del contrato
        // (config/sso.php) y la comprobacion no se puede desactivar. Si alguien
        // igual vaciara la config, se falla CERRADO: `$esperado === ''` rechaza
        // todo en vez de dejar pasar todo, que es como se equivoca un sistema
        // de auth que no quiere titulares.
        $esperado = (string) config('sso.gateway_signature');
        $recibido = (string) $request->header($headers['gateway'], '');

        // Comparacion simple y no `hash_equals`: el sello NO ES UN SECRETO (esta
        // impreso en el repo del gateway), asi que una comparacion en tiempo
        // constante no protegeria de nada y sugeriria lo contrario a quien lea.
        if ($esperado === '' || $recibido !== $esperado) {
            $this->logRejection($request, 'gateway_signature_mismatch',
                'La peticion no declara el sello del gateway, o declara uno que no coincide.');

            return $this->rechazar($request,
                'Esta API se consume unicamente a traves del API Gateway del SSO.');
        }

        $userId = $request->header($headers['id']);

        if (blank($userId)) {
            // Este rechazo NO puede ser mudo. En el VPS eso hace indistinguibles
            // dos causas opuestas: que el gateway no este inyectando la
            // identidad (bug de despliegue, hay que arreglar NGINX) o que
            // alguien este pegando directo al backend (agujero de red). El
            // campo request_id_source que publica RequestContext separa las dos.
            //
            // Y es 401, no 500: llegar aca significa que el sello estaba bien y
            // la identidad no, o sea gateway mal configurado. Un 500 haria que
            // el cliente reintente contra algo que nunca va a funcionar, y
            // ademas ensuciaria la alerta de errores de servidor con un problema
            // de configuracion (spec: "responde 401, no 500").
            $this->logRejection($request, 'missing_identity_header',
                'Falta la cabecera de identidad que inyecta el gateway.');

            return $this->rechazar($request,
                'El gateway no inyecto la identidad del usuario.');
        }

        // CONTRATO X-User-Roles: lista separada por COMAS, tal como la emite
        // AuthController@validateToken en el SSO. Por eso un nombre de rol NO
        // puede contener una coma: se partiria en dos roles y concederia
        // privilegios que nadie otorgo. El SSO descarta esos nombres antes de
        // emitirlos; aca ademas se tiran las entradas vacias (cabecera ausente,
        // comas de mas o sobrantes) para no dar por valido un rol "".
        //
        // La cabecera AUSENTE es un caso normal, no un error: NGINX omite un
        // proxy_set_header de valor vacio, asi que una persona sin ningun rol de
        // TR3SLOG ni de plataforma llega SIN X-User-Roles. Resultado: lista
        // vacia, y que decida la guarda de rol. Mismo criterio para X-User-Name.
        $roles = array_values(array_filter(
            array_map('trim', explode(',', (string) $request->header($headers['roles'], ''))),
            static fn (string $role): bool => $role !== '',
        ));

        $identity = [
            'id'       => $userId,
            'clerk_id' => $request->header($headers['clerk_id']),
            'email'    => $request->header($headers['email']),
            'name'     => $request->header($headers['name']),
            'roles'    => $roles,
        ];

        // La identidad vive UNICAMENTE en el request. No se publica como binding
        // del contenedor: bajo Octane/Swoole el contenedor sobrevive entre
        // peticiones y un singleton 'sso.user' filtraria la identidad de un
        // usuario a la peticion del siguiente.
        $request->attributes->set('sso_user', $identity);

        return $next($request);
    }

    /**
     * -- DIVERGENCIA 1: el slug es `unauthenticated` y el cuerpo lleva request_id.
     *
     * MSH devuelve `'error' => 'unauthorized'`, y ese slug NO ESTA en el catalogo
     * cerrado de trece de contrato_api_v1_msh.md — el catalogo esta congelado con
     * un assertSame sobre ApiErrorCatalog::slugs(), asi que inventar uno es un
     * cambio de contrato unilateral. El propio gateway, para esta misma
     * condicion, emite `unauthenticated`: el backend de MSH contradice a su
     * propio NGINX. Con dos vocabularios, un cliente que siga el documento no
     * reconoce su propio 401 y no vuelve al login.
     *
     * Esto es un BUG DE MSH, no una preferencia de TR3SLOG: se reporta y se
     * porta hacia alla.
     *
     * El `request_id` va en el cuerpo porque es lo unico con lo que se encuentra
     * esta peticion en los logs del gateway, del SSO y del backend. Un error de
     * auth sin correlacion es un ticket de soporte irresoluble.
     */
    private function rechazar(Request $request, string $mensaje): Response
    {
        return response()->json([
            'error'      => 'unauthenticated',
            'message'    => $mensaje,
            'request_id' => RequestContext::resolveFor($request),
        ], 401);
    }

    /**
     * Deja registro del motivo concreto del 401.
     *
     * QUE SE LOGUEA Y QUE NO:
     * solo se registran HECHOS sobre las cabeceras (si estaban o no), nunca su
     * CONTENIDO. Ni Authorization, ni el bearer, ni el valor de la firma
     * recibida: un log de diagnostico que copia credenciales las convierte en un
     * archivo de texto plano en un volumen compartido, o sea en una filtracion
     * con otro nombre. Saber que "vino un Authorization" ya responde la pregunta
     * util (el cliente mando token pero el gateway no lo tradujo); saber cual
     * era, no aporta nada y cuesta muchisimo.
     *
     * El request_id no se pasa aca: lo publica RequestContext con
     * Log::withContext y Monolog lo agrega solo a esta y a cualquier otra linea
     * de la misma peticion.
     */
    private function logRejection(Request $request, string $reason, string $detail): void
    {
        Log::warning('gateway.auth rechazo 401: '.$detail, [
            'event'  => 'gateway_auth_rejected',
            'reason' => $reason,
            'method' => $request->getMethod(),
            'path'   => $request->path(),
            // IP de quien abrio la conexion TCP con este backend. Si no es la
            // del contenedor del gateway, alguien lo esta puenteando.
            'client_ip' => $request->ip(),
            // Presencia, NO valor. Ver el bloque de arriba.
            'has_authorization_header' => $request->hasHeader('Authorization'),
            'has_gateway_signature'    => $request->hasHeader(config('sso.headers.gateway')),
            'has_identity_header'      => $request->hasHeader(config('sso.headers.id')),
        ]);
    }
}
