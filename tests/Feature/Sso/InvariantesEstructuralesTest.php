<?php

namespace Tests\Feature\Sso;

use App\Http\Middleware\AuthenticateFromGateway;
use App\Http\Middleware\RequireSsoRole;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Routing\Route as RutaRegistrada;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Invariantes ESTRUCTURALES. No verifican una respuesta: verifican que no se
 * pueda volver a cometer el error.
 *
 * POR QUE ESTOS DOS TESTS SON DISTINTOS DE TODOS LOS DEMAS:
 * los agujeros de `1-proposal.md §1` no los abrio nadie a proposito. Se abrieron
 * porque una ruta se agrego sin guarda y NADIE SE ENTERO — el sintoma de ese
 * error es «todo funciona». Un test de comportamiento cubre la ruta que hoy
 * existe; estos cubren la que alguien agregue el martes que viene.
 *
 * Sobreviven a la migracion entera: cuando los Lotes 5 y 8 monten el resto del
 * dominio por el gateway, estas mismas dos afirmaciones siguen valiendo sin
 * tocarse.
 */
class InvariantesEstructuralesTest extends TestCase
{
    // -----------------------------------------------------------------------
    //  Utilidades sobre la tabla de rutas
    // -----------------------------------------------------------------------

    /** @return list<RutaRegistrada> Todas las rutas bajo `api/`, sin duplicar por metodo. */
    private function rutasApi(): array
    {
        return array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            static fn (RutaRegistrada $r): bool => str_starts_with($r->uri(), 'api/')
        ));
    }

    private function etiqueta(RutaRegistrada $r): string
    {
        return implode('|', array_diff($r->methods(), ['HEAD'])).' /'.$r->uri();
    }

    /**
     * La cadena REAL de la ruta, con los alias ya resueltos a nombres de clase.
     *
     * OJO: `$ruta->gatherMiddleware()` NO sirve para esto. Devuelve lo que quedo
     * escrito en la definicion —`'gateway.auth'`, `'auth:sanctum'`— sin mirar el
     * mapa de alias ni el grupo `api`. Un test escrito contra eso pasa a estar
     * verde con solo escribir bien el string, aunque el alias no exista o apunte
     * a otra clase. `Router::gatherRouteMiddleware()` es lo que usa por dentro
     * `php artisan route:list -v`: resuelve alias, grupos y prioridades, o sea
     * la cadena que de verdad va a correr.
     *
     * @return list<string>
     */
    private function cadenaResuelta(RutaRegistrada $r): array
    {
        return array_values(array_filter(
            app('router')->gatherRouteMiddleware($r),
            'is_string'
        ));
    }

    /**
     * Las UNICAS rutas de `api/` que pueden vivir sin ninguna guarda, cada una
     * con el motivo por el que puede.
     *
     * ESTA LISTA ES EL CORAZON DEL TEST. Agregar una entrada aca tiene que doler
     * y tiene que verse en el diff: es la unica forma de que abrir una ruta al
     * publico sea una DECISION y no un descuido. Si el test falla porque
     * agregaste una ruta, la pregunta no es "como lo callo", es "por que esta
     * abierta".
     *
     * @return array<string, string>
     */
    private function publicasPermitidas(): array
    {
        return [
            // Los tres logins. Mueren en el Lote 9, cuando el SSO los reemplace.
            'POST /api/register'            => 'Alta publica de la web. Ya no acepta `role` (Lote 4).',
            'POST /api/login'               => 'Login de la web Next.',
            'POST /api/forgot-password'     => 'Reset: por definicion lo pide quien no puede entrar.',
            'POST /api/verify-reset-token'  => 'Idem. La credencial es el token del correo.',
            'POST /api/reset-password'      => 'Idem.',
            'POST /api/app/register'        => 'Alta de la app de clientes (`/app/*`, D3).',
            'POST /api/app/login'           => 'Login de la app de clientes.',
            'POST /api/app/forgot-password' => 'Reset de la app de clientes.',
            'POST /api/app/reset-password'  => 'Idem.',
            'POST /api/driver/register'     => 'Alta de la app de conductores (binario en la calle, H3).',
            'POST /api/driver/login'        => 'Login de la app de conductores.',

            // Formularios de gente que todavia no es cliente.
            'POST /api/contact'             => 'Formulario de contacto publico del sitio.',
            'POST /api/app/quotes'          => 'Cotizacion sin cuenta: es el embudo comercial.',
            'GET /api/app/quotes/track/{tracking_code}' => 'Seguimiento por codigo. La credencial es el codigo.',

            // Invitaciones: la mitad del invitado. Ver el comentario largo en
            // routes/api.php — exigirles identidad resuelta las mata.
            'POST /api/invitations/verify'  => 'La llama el invitado, que todavia no tiene cuenta.',
            'POST /api/invitations/accept'  => 'Es el codigo que CREA la cuenta del invitado.',
        ];
    }

    // =======================================================================
    //  4.11 — Cobertura: ninguna ruta queda sin guarda por descuido
    // =======================================================================

    /**
     * La forma FUERTE del requisito `Cobertura total de rutas de dominio`.
     *
     * POR QUE NO SE ESCRIBIO COMO LO PIDE LA SPEC ("cada ruta de dominio tiene
     * `gateway.auth`"): hoy NINGUNA ruta de dominio la tiene, y no debe tenerla
     * — el conductor se migra en el Lote 5 y el resto en el Lote 8, con el
     * bloque `auth:sanctum` vivo en paralelo hasta el Lote 9. Un test escrito
     * asi nace rojo y se queda rojo durante cuatro lotes, o sea que se commitea
     * marcado como skipped y no protege nada. Un test que no puede estar en
     * verde por diseño no es una red de seguridad, es ruido.
     *
     * La invariante que SI vale hoy y sigue valiendo despues del Lote 9 es esta:
     * toda ruta de `api/` esta detras de ALGUNA guarda —`auth:sanctum` (camino
     * viejo) o `gateway.auth` (camino nuevo)— salvo las que figuran, una por
     * una y con su motivo, en la lista de arriba. Cuando el Lote 8 mueva el
     * dominio al gateway, la lista no cambia y el test sigue apretando.
     */
    public function test_ninguna_ruta_de_api_queda_sin_guarda_fuera_de_la_lista_publica(): void
    {
        $permitidas = $this->publicasPermitidas();
        $huerfanas  = [];

        foreach ($this->rutasApi() as $ruta) {
            $middleware = $this->cadenaResuelta($ruta);

            // `auth:sanctum` llega resuelto como
            // `Illuminate\\Auth\\Middleware\\Authenticate:sanctum`. Se aceptan las
            // dos formas para que el test no dependa de como este escrita la ruta.
            $tieneSanctum = (bool) array_filter(
                $middleware,
                static fn (string $m): bool => str_contains($m, 'auth:sanctum')
                    || str_contains($m, Authenticate::class.':sanctum')
            );
            $tieneGateway = in_array(AuthenticateFromGateway::class, $middleware, true);

            if ($tieneSanctum || $tieneGateway) {
                continue;
            }

            $etiqueta = $this->etiqueta($ruta);

            if (! array_key_exists($etiqueta, $permitidas)) {
                $huerfanas[] = $etiqueta;
            }
        }

        $this->assertSame([], $huerfanas, implode("\n", [
            '',
            'Hay rutas de `api/` sin ninguna guarda de autenticacion:',
            '',
            '    '.implode("\n    ", $huerfanas),
            '',
            'Esto es EXACTAMENTE como se abrieron los tres agujeros de',
            '`1-proposal.md §1`: nadie los abrio queriendo, se agrego una ruta y',
            'el sintoma fue que todo funcionaba.',
            '',
            'Si la ruta tiene que estar protegida -> ponele `auth:sanctum` (camino',
            'viejo) o `gateway.auth` (camino del SSO, bajo el prefijo `treslog`).',
            'Si de verdad tiene que ser publica -> agregala a publicasPermitidas()',
            'CON EL MOTIVO ESCRITO, para que la proxima revision lo vea.',
            '',
        ]));
    }

    /**
     * La mitad del requisito que si se puede exigir entera hoy: la superficie
     * del SSO. Todo lo que cuelgue de `api/treslog/*` viene por el gateway y
     * nada mas que por ahi, asi que `gateway.auth` no es opcional en ese
     * prefijo. Sin esta guarda, una ruta del bloque nuevo montada sin cadena
     * seria la ruta MAS abierta del sistema: sin sesion, sin token y sin sello.
     *
     * Este es el test que va creciendo solo: cada ruta que los Lotes 5 y 8
     * agreguen bajo `treslog` queda cubierta sin tocar una linea de aca.
     */
    public function test_toda_ruta_del_prefijo_treslog_exige_gateway_auth(): void
    {
        $sinGuarda = [];
        $vistas    = 0;

        foreach ($this->rutasApi() as $ruta) {
            if (! str_starts_with($ruta->uri(), 'api/treslog/')) {
                continue;
            }

            $vistas++;

            if (! in_array(AuthenticateFromGateway::class, $this->cadenaResuelta($ruta), true)) {
                $sinGuarda[] = $this->etiqueta($ruta);
            }
        }

        // Si el prefijo quedara vacio, el foreach de arriba no comprueba nada y
        // el test estaria verde sin haber mirado una sola ruta.
        $this->assertGreaterThan(0, $vistas, 'No hay ni una ruta bajo `api/treslog/`: el camino del SSO no esta montado.');

        $this->assertSame([], $sinGuarda,
            "Rutas bajo `api/treslog/` sin `gateway.auth`:\n    ".implode("\n    ", $sinGuarda).
            "\nEsas rutas no tienen sesion, ni token, ni sello: son la superficie mas abierta del sistema.");
    }

    // =======================================================================
    //  4.8 — Los roles: prefijo obligatorio y `Super Admin` prohibido
    // =======================================================================

    /**
     * R3: un rol sin el prefijo `treslog:` NO DA ERROR EN NINGUN LADO. El SSO lo
     * filtra por alcance de aplicacion y ese rol nunca viaja: ni en
     * `X-User-Roles` ni en `/api/v1/authorization`. La persona lo tiene
     * asignado, la aplicacion no se entera, y el sintoma es "no me deja entrar"
     * sin una sola linea de log.
     */
    public function test_todo_rol_de_config_sso_lleva_el_prefijo_de_la_aplicacion(): void
    {
        $roles = config('sso.roles');

        $this->assertNotEmpty($roles, 'config(\'sso.roles\') quedo vacio: ninguna guarda de rol podria resolverse.');

        foreach ($roles as $corto => $completo) {
            $this->assertStringStartsWith('treslog:', $completo,
                "El rol «{$corto}» quedo como «{$completo}», sin el prefijo `treslog:`. ".
                'El SSO no lo emite nunca y la falla es silenciosa.');

            // Una coma parte el rol en dos al leer `X-User-Roles`, que viene
            // separada por comas: seria conceder un privilegio que nadie otorgo.
            $this->assertStringNotContainsString(',', $completo,
                "El rol «{$completo}» tiene una coma: se partiria en dos al leer X-User-Roles.");
        }
    }

    /**
     * R4: `Super Admin` es rol de PLATAFORMA. Mapear ahi al admin de TR3SLOG le
     * daria poder de admin en todas las aplicaciones del ecosistema.
     *
     * Se busca el literal en `app/` porque ese es el lugar donde el error
     * entraria: una guarda escrita a mano en un controlador o un middleware.
     */
    public function test_el_literal_super_admin_no_aparece_en_app(): void
    {
        $encontrados = [];
        $base        = base_path('app');

        $archivos = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($archivos as $archivo) {
            if ($archivo->getExtension() !== 'php') {
                continue;
            }

            $contenido = file_get_contents($archivo->getPathname());

            if (str_contains($contenido, 'Super Admin')) {
                $encontrados[] = str_replace(base_path().'/', '', $archivo->getPathname());
            }
        }

        $this->assertSame([], $encontrados,
            "El literal «Super Admin» aparece en:\n    ".implode("\n    ", $encontrados).
            "\nEs un rol de PLATAFORMA: concede en todas las aplicaciones del ecosistema, no solo en TR3SLOG.");
    }

    /**
     * El complemento de los dos de arriba, y el que cierra el circulo: de nada
     * sirve que `config('sso.roles')` este bien si una ruta escribe el rol a
     * mano. Toda guarda `sso.role:` montada tiene que nombrar un rol que este en
     * la config — que es lo mismo que decir que salio de ahi.
     */
    public function test_ninguna_ruta_exige_un_rol_que_no_este_en_config_sso(): void
    {
        $conocidos = array_values(config('sso.roles'));
        $invalidos = [];

        foreach ($this->rutasApi() as $ruta) {
            foreach ($this->cadenaResuelta($ruta) as $m) {
                if (! str_starts_with($m, RequireSsoRole::class.':')) {
                    continue;
                }

                foreach (explode(',', substr($m, strlen(RequireSsoRole::class) + 1)) as $rol) {
                    if (! in_array($rol, $conocidos, true)) {
                        $invalidos[] = $this->etiqueta($ruta).' exige «'.$rol.'»';
                    }
                }
            }
        }

        $this->assertSame([], $invalidos,
            "Rutas que exigen un rol escrito a mano:\n    ".implode("\n    ", $invalidos).
            "\nUsa `'sso.role:'.config('sso.roles.<corto>')`: un rol mal escrito no da error, simplemente no entra nadie.");
    }
}
