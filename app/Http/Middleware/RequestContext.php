<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adjunta al contexto de log de Laravel el identificador de correlacion de la
 * peticion, para que TODA linea que TR3SLOG escriba durante esa peticion se
 * pueda cruzar con la del gateway y la del SSO.
 *
 * POR QUE ESTE MIDDLEWARE EXISTE:
 * el request_id lo genera NGINX (variable nativa $request_id) porque es el unico
 * punto por el que pasa TODO el trafico. TR3SLOG no lo inventa: lo recibe en
 * X-Request-Id y se limita a propagarlo al log. Asi, un error que el usuario
 * reporta con su id se sigue hacia atras: cliente -> gateway -> SSO -> TR3SLOG.
 *
 * SIN CABECERA: no se aborta ni se ignora. Se genera un id propio y se MARCA
 * como generado localmente, porque "esta peticion no paso por el gateway" es
 * exactamente el dato que en el VPS distingue un despliegue mal cableado de un
 * acceso directo al backend. Un id sin origen seria peor que no tener id.
 *
 * Copiado de MSH (app/Http/Middleware/RequestContext.php). Unico cambio de
 * comportamiento: el prefijo del id generado, ver generateId().
 */
class RequestContext
{
    /** Clave con la que el request_id queda disponible en el propio request. */
    public const ATTRIBUTE = 'request_id';

    /**
     * Prefijo del id generado localmente.
     *
     * `treslog-` y no `msh-`: el prefijo existe justamente para saber QUIEN
     * genero el id cuando aparece en un log agregado con el de las otras
     * aplicaciones. Copiar el de MSH lo vaciaria de sentido — dos backends
     * distintos emitiendo `msh-...` es peor que no tener prefijo, porque
     * mentiria con confianza.
     */
    private const PREFIX = 'treslog-';

    /**
     * Forma aceptable de un request_id ajeno.
     *
     * El valor llega en una cabecera, o sea que en el peor caso lo controla
     * quien pega directo al backend. Se acota a caracteres inocuos y a 128
     * bytes para que nadie use el log como buzon: sin este filtro, un tercero
     * podria empujar kilobytes de basura por linea de log (llenar el disco del
     * VPS) o meter saltos de linea que rompan el parseo del JSON aguas abajo.
     * El $request_id de NGINX son 32 hex, asi que este limite nunca lo molesta.
     */
    private const VALID_ID = '/^[A-Za-z0-9._:-]{1,128}$/';

    public function handle(Request $request, Closure $next): Response
    {
        [$requestId, $source] = $this->deducirId($request);

        $context = [
            'request_id'        => $requestId,
            'request_id_source' => $source,
        ];

        // El user_id lo resuelve el gateway con su subpeticion al SSO; aca solo
        // se lee para poder responder "que hizo este usuario" filtrando el log.
        // Se lee la cabecera cruda a proposito: este middleware corre ANTES que
        // AuthenticateFromGateway, para que hasta los rechazos 401 lleven
        // contexto. Que el valor sea confiable o no es problema de aquel; para
        // el log alcanza con saber que identidad se declaro.
        $userId = $request->header(config('sso.headers.id'));
        if (filled($userId)) {
            $context['user_id'] = $userId;
        }

        Log::withContext($context);

        // Disponible para el resto de la peticion (respuestas de diagnostico,
        // handlers de excepcion, etc.) sin volver a leer cabeceras.
        $request->attributes->set(self::ATTRIBUTE, $requestId);

        // Se normaliza la cabecera entrante para que cualquier codigo que la
        // lea mas adelante vea SIEMPRE un id valido, incluso si no vino uno.
        $request->headers->set(config('sso.headers.request_id'), $requestId);

        // NO se escribe la cabecera en la RESPUESTA a proposito: el eco hacia el
        // cliente lo hace el gateway, que es quien genera el id. Si lo pusieran
        // los dos, el navegador recibiria X-Request-Id duplicado.
        $inicio   = microtime(true);
        $response = $next($request);

        $this->registrarPeticion($request, $response, $context, $inicio);

        return $response;
    }

    /**
     * El request_id de esta peticion, venga de donde venga.
     *
     * DIVERGENCIA respecto de MSH: este metodo no existe alla. Aca hace falta
     * porque la spec exige que el cuerpo de TODA respuesta de error propia lleve
     * `request_id` (contrato_api_v1_msh.md: "es lo unico con lo que nosotros
     * encontramos la peticion en los logs"), y durante el Lote 3 este middleware
     * todavia NO esta montado en el grupo `api` — el codigo es inerte a
     * proposito. Un 401 con `"request_id": null` cumpliria la letra del JSON y
     * romperia lo unico para lo que sirve el campo.
     *
     * Es idempotente: si `handle()` ya corrio, devuelve el mismo valor; si no,
     * lo deduce igual y lo deja publicado en el request para que nadie mas
     * abajo genere un SEGUNDO id para la misma peticion.
     */
    public static function resolveFor(Request $request): string
    {
        $publicado = $request->attributes->get(self::ATTRIBUTE);

        if (is_string($publicado) && $publicado !== '') {
            return $publicado;
        }

        [$requestId] = (new self)->deducirId($request);

        $request->attributes->set(self::ATTRIBUTE, $requestId);

        return $requestId;
    }

    /**
     * Decide el id y de donde salio.
     *
     * @return array{0: string, 1: string} [$requestId, $source]
     */
    private function deducirId(Request $request): array
    {
        $incoming = $request->header(config('sso.headers.request_id'));

        if (blank($incoming)) {
            // Nadie inyecto el id: la peticion no vino del gateway.
            return [$this->generateId(), 'treslog-generated'];
        }

        if (preg_match(self::VALID_ID, $incoming) === 1) {
            return [$incoming, 'gateway'];
        }

        // Llego una cabecera pero con una forma que el gateway nunca emite.
        // Se descarta el valor (no se loguea: es texto de un tercero) y se
        // deja constancia del hecho, que ya es en si una senial de alarma.
        return [$this->generateId(), 'invalid-header'];
    }

    /**
     * Emite UNA linea por peticion.
     *
     * Log::withContext() solo DECORA las lineas que escriba otro codigo; no
     * escribe ninguna. Sin este metodo, el backend solo dejaria rastro cuando
     * rechaza con 401, asi que el camino feliz seria invisible y la traza de
     * tres saltos se cortaria justo en el ultimo: el request_id aparece en el
     * log del gateway y en el del SSO, pero nunca en el de la aplicacion.
     *
     * Es el eslabon que hace utilizable toda la capa de correlacion: sin el, el
     * caso "el conductor dice que la app le devuelve datos raros" no se puede
     * seguir, porque no hay ninguna linea de TR3SLOG que buscar.
     */
    private function registrarPeticion(
        Request $request,
        Response $response,
        array $context,
        float $inicio
    ): void {
        $status = $response->getStatusCode();

        $context['event']       = 'http.request';
        $context['method']      = $request->getMethod();
        $context['path']        = '/' . ltrim($request->path(), '/');
        $context['status']      = $status;
        $context['duration_ms'] = (int) round((microtime(true) - $inicio) * 1000);

        // Mismo criterio de niveles que el SSO, para que un filtro por severidad
        // sobre los tres archivos devuelva resultados coherentes: un 5xx es un
        // problema del servidor, un 4xx es diagnostico y no deberia alarmar.
        $level = match (true) {
            $status >= 500 => 'error',
            $status >= 400 => 'warning',
            default        => 'info',
        };

        Log::log($level, sprintf('%s %s -> %d', $context['method'], $context['path'], $status), $context);
    }

    /**
     * Id propio, con prefijo, para que en el log salte a la vista que NO lo
     * genero NGINX aunque nadie mire el campo request_id_source.
     */
    private function generateId(): string
    {
        return self::PREFIX.Str::uuid()->toString();
    }
}
