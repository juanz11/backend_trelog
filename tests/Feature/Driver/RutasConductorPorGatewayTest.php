<?php

namespace Tests\Feature\Driver;

use App\Http\Middleware\AuthenticateFromGateway;
use App\Http\Middleware\EnsureUserIsDriver;
use App\Http\Middleware\RequireSsoRole;
use App\Http\Middleware\ResolveDomainUser;
use App\Models\DeliveryRoute;
use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RutaRegistrada;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * El segundo montaje de la superficie del conductor: `/api/treslog/driver/*`.
 *
 * QUE PROTEGE ESTE ARCHIVO, dicho sin vueltas: que el camino nuevo no sea MAS
 * ABIERTO que el viejo. Es el error tipico de una migracion de auth — el bloque
 * nuevo se escribe "para que ande" y termina concediendo mas que el que
 * reemplaza, porque nadie compara las dos cadenas de middleware lado a lado.
 *
 * 4-tasks.md §5.2 pedia montar este bloque con `['gateway.auth','gateway.user']`
 * y nada mas. Con esa cadena, CUALQUIER identidad del SSO que tenga fila espejo
 * en `users` —un cliente, contabilidad, quien sea— llega a
 * `/api/treslog/driver/payroll` y `/routes`. El test
 * `test_una_identidad_del_SSO_que_NO_es_conductor_no_entra_por_el_camino_nuevo`
 * es el que agarra eso: sacale `sso.role` al bloque de routes/api.php y se pone
 * rojo.
 *
 * La contracara esta en CaracterizacionRutasConductorTest: el camino viejo
 * (`/api/driver/*`, `auth:sanctum`) sigue intacto y esos 8 tests siguen verdes.
 */
class RutasConductorPorGatewayTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Un conductor que entra POR EL SSO: fila espejo anclada por `sso_user_id` y
     * NADA en `role_user`.
     *
     * Que no tenga rol local no es un descuido del test, es el escenario real
     * (hallazgo 2): a la persona la da de alta el SSO, y su fila en TR3SLOG es
     * un espejo con el ancla y poco mas. Si alguna guarda del camino nuevo leyera
     * `role_user`, este usuario —que ES conductor— comeria un 403.
     */
    private function espejoDeConductor(string $ssoUserId = '4242'): User
    {
        $u = User::factory()->create();

        // `sso_user_id` esta FUERA de $fillable a proposito (es el ancla de la
        // identidad): `fill()` lo descarta en silencio y el test pasaria a
        // probar otra cosa. Por eso `forceFill`.
        $u->forceFill(['sso_user_id' => $ssoUserId])->save();

        DriverProfile::factory()->create(['user_id' => $u->id]);

        return $u->fresh();
    }

    /** Cabeceras de una peticion que SI paso por el gateway del SSO. */
    private function delGateway(array $extra = []): array
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

    // =======================================================================
    //  5.5 (a) — Estructural: la cadena montada es la que se creia montar
    // =======================================================================

    /** @return list<RutaRegistrada> */
    private function rutasBajo(string $prefijo): array
    {
        return array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            static fn (RutaRegistrada $r): bool => str_starts_with($r->uri(), $prefijo)
        ));
    }

    /**
     * La cadena REAL, con los alias ya resueltos a nombres de clase.
     *
     * `gatherMiddleware()` NO sirve: devuelve el string tal como quedo escrito en
     * la ruta, asi que un alias inexistente o mal apuntado pasa el test igual.
     * `gatherRouteMiddleware()` es lo que usa `route:list -v` por dentro.
     *
     * @return list<string>
     */
    private function cadena(RutaRegistrada $r): array
    {
        return array_values(array_filter(app('router')->gatherRouteMiddleware($r), 'is_string'));
    }

    private function etiqueta(RutaRegistrada $r): string
    {
        return implode('|', array_diff($r->methods(), ['HEAD'])).' /'.$r->uri();
    }

    public function test_toda_ruta_de_treslog_driver_lleva_las_TRES_guardas(): void
    {
        $esperadas = [
            AuthenticateFromGateway::class,
            RequireSsoRole::class.':'.config('sso.roles.driver'),
            ResolveDomainUser::class,
        ];

        $faltantes = [];
        $vistas    = 0;

        foreach ($this->rutasBajo('api/treslog/driver/') as $ruta) {
            $vistas++;
            $cadena = $this->cadena($ruta);

            foreach ($esperadas as $guarda) {
                if (! in_array($guarda, $cadena, true)) {
                    $faltantes[] = $this->etiqueta($ruta).' -> le falta '.$guarda;
                }
            }
        }

        // Sin esto, borrar el bloque entero de routes/api.php deja el test VERDE:
        // el foreach no itera y no comprueba nada.
        $this->assertGreaterThan(0, $vistas, 'No hay ni una ruta bajo `api/treslog/driver/`: el camino del SSO no esta montado.');

        $this->assertSame([], $faltantes, implode("\n", [
            '',
            'Rutas del conductor por el gateway con la cadena incompleta:',
            '',
            '    '.implode("\n    ", $faltantes),
            '',
            'Las tres guardas hacen cosas DISTINTAS y ninguna cubre a la otra:',
            '  gateway.auth -> la peticion vino del gateway y trae identidad',
            '  sso.role     -> esa identidad es la de un CONDUCTOR',
            '  gateway.user -> esa identidad tiene fila en `users` (los controladores',
            '                  hacen `$request->user()->id` contra nueve FKs)',
            '',
        ]));
    }

    /**
     * El orden no es cosmetico: `sso.role` antes que `gateway.user` significa que
     * quien no es conductor se va sin que TR3SLOG toque la base ni refresque el
     * espejo de identidad, y sin enterarse de si esa persona esta o no dada de
     * alta en TR3SLOG.
     */
    public function test_la_guarda_de_rol_corre_antes_de_ir_a_la_base(): void
    {
        foreach ($this->rutasBajo('api/treslog/driver/') as $ruta) {
            $cadena = $this->cadena($ruta);

            $posRol   = array_search(RequireSsoRole::class.':'.config('sso.roles.driver'), $cadena, true);
            $posUser  = array_search(ResolveDomainUser::class, $cadena, true);

            $this->assertIsInt($posRol, $this->etiqueta($ruta).' no tiene la guarda de rol.');
            $this->assertIsInt($posUser, $this->etiqueta($ruta).' no tiene `gateway.user`.');
            $this->assertLessThan($posUser, $posRol,
                $this->etiqueta($ruta).': `gateway.user` corre antes que `sso.role`, o sea que TR3SLOG '.
                'consulta y actualiza la fila de alguien que ni siquiera es conductor.');
        }
    }

    /**
     * La regla dura del lote: el camino viejo NO SE TOCA. Hay conductores con la
     * app instalada en la calle y la URL congelada en el binario (H3).
     */
    public function test_el_camino_viejo_sigue_con_auth_sanctum_y_la_guarda_local(): void
    {
        $publicas = ['api/driver/register', 'api/driver/login'];
        $flojas   = [];
        $vistas   = 0;

        foreach ($this->rutasBajo('api/driver/') as $ruta) {
            if (in_array($ruta->uri(), $publicas, true)) {
                continue;
            }

            $vistas++;
            $cadena = $this->cadena($ruta);

            $tieneSanctum = (bool) array_filter(
                $cadena,
                static fn (string $m): bool => str_contains($m, 'auth:sanctum')
                    || str_contains($m, Authenticate::class.':sanctum')
            );

            if (! $tieneSanctum || ! in_array(EnsureUserIsDriver::class, $cadena, true)) {
                $flojas[] = $this->etiqueta($ruta);
            }
        }

        $this->assertGreaterThan(0, $vistas, 'Desaparecieron las rutas de `api/driver/`: la app instalada se quedo sin backend.');
        $this->assertSame([], $flojas,
            "El bloque viejo perdio guardas en:\n    ".implode("\n    ", $flojas).
            "\nEste lote solo AGREGA un montaje; el camino de la app instalada se retira en el Lote 9.");
    }

    /**
     * `logout` borra el access token de Sanctum. Por el camino del SSO no hay
     * ninguno —el gateway vacia el Authorization antes del proxy— asi que seria
     * `null->delete()`: un 500 justo cuando la persona quiere irse. Y ademas la
     * credencial real la revoca el SSO, no TR3SLOG.
     */
    public function test_logout_NO_se_monta_por_el_camino_del_SSO(): void
    {
        $uris = array_map(
            static fn (RutaRegistrada $r): string => $r->uri(),
            Route::getRoutes()->getRoutes()
        );

        $this->assertContains('api/driver/logout', $uris,
            'Se cayo el logout del camino viejo: la app instalada no puede cerrar sesion.');

        $this->assertNotContains('api/treslog/driver/logout', $uris,
            'Alguien mudo `logout` a routes/domain-driver.php. Por el gateway no hay token de '.
            'Sanctum que borrar: `currentAccessToken()` devuelve null y la ruta tira 500.');
    }

    // =======================================================================
    //  5.5 (b) — Comportamiento con las cabeceras del gateway simuladas
    // =======================================================================

    public function test_un_conductor_del_SSO_ve_SUS_rutas_por_el_camino_nuevo(): void
    {
        $mio   = $this->espejoDeConductor();
        $ajeno = User::factory()->create();

        DeliveryRoute::factory()->create(['driver_id' => $mio->id, 'code' => 'R-MIA']);
        DeliveryRoute::factory()->create(['driver_id' => $ajeno->id, 'code' => 'R-AJENA']);

        $r = $this->withHeaders($this->delGateway())
            ->getJson('/api/treslog/driver/routes')
            ->assertOk();

        // Mismo invariante que el camino viejo: el aislamiento es por FILTRO.
        $this->assertStringContainsString('R-MIA', $r->getContent());
        $this->assertStringNotContainsString('R-AJENA', $r->getContent(),
            'Una ruta de otro conductor se filtro en la lista del camino nuevo.');
    }

    /**
     * EL TEST DEL HALLAZGO 9.
     *
     * Misma peticion, mismo espejo, misma fila en `users`. Lo unico que cambia es
     * el rol que emitio el SSO. Con la cadena que pedia 4-tasks.md §5.2
     * (`gateway.auth` + `gateway.user` y nada mas) esto responde 200 y un cliente
     * ve el reparto y la liquidacion de los conductores.
     */
    public function test_una_identidad_del_SSO_que_NO_es_conductor_no_entra_por_el_camino_nuevo(): void
    {
        $this->espejoDeConductor();

        foreach ([
            '/api/treslog/driver/routes',
            '/api/treslog/driver/payroll',
            '/api/treslog/driver/dashboard',
            '/api/treslog/driver/me',
        ] as $ruta) {
            $this->withHeaders($this->delGateway(['X-User-Roles' => 'treslog:customer', 'X-Request-Id' => 'sso-jueces-403']))
                ->getJson($ruta)
                ->assertStatus(403, "«{$ruta}» dejo entrar a una identidad sin el rol de conductor")
                ->assertJsonPath('error', 'forbidden')
                // El 403 es el error mas frecuente del camino nuevo, y era el unico
                // sin request_id: el id entrante se perdia y no habia con que cruzar
                // la captura del cliente contra los logs. Lo marco la auditoria.
                ->assertJsonPath('request_id', 'sso-jueces-403')
                // Y sin `required`: el sobre es cerrado, y los roles que abren la
                // puerta no se le cuentan a quien la encontro cerrada.
                ->assertJsonMissingPath('required');
        }
    }

    /**
     * El complemento del de arriba, y el que impide "arreglarlo" con el
     * middleware `driver` de siempre: `EnsureUserIsDriver` hace `hasRole('driver')`
     * contra `role_user` LOCAL, que para quien entra por el SSO esta vacio
     * (hallazgo 2). Este usuario NO tiene rol local y tiene que entrar igual.
     */
    public function test_el_conductor_del_SSO_entra_SIN_tener_fila_en_role_user(): void
    {
        $u = $this->espejoDeConductor();

        $this->assertFalse($u->hasRole('driver'),
            'El fixture dejo de representar el caso real: un espejo del SSO no tiene roles locales.');

        $this->withHeaders($this->delGateway())
            ->getJson('/api/treslog/driver/dashboard')
            ->assertOk();
    }

    public function test_sin_cabeceras_del_gateway_responde_401_unauthenticated(): void
    {
        $this->espejoDeConductor();

        $this->getJson('/api/treslog/driver/routes')
            ->assertStatus(401)
            ->assertJsonPath('error', 'unauthenticated');
    }

    /**
     * Sello valido, rol de conductor valido, y NINGUNA fila espejo. Es la persona
     * que existe en el SSO y todavia no fue dada de alta en TR3SLOG (R10, la
     * migracion de cuentas esta fuera de alcance).
     *
     * 403 y no 500: el 500 mandaria a mirar los logs del servidor por algo que es
     * un dato que falta, y el cliente reintentaria contra algo que nunca va a
     * funcionar.
     */
    public function test_identidad_del_SSO_sin_fila_espejo_responde_403_y_no_500(): void
    {
        $u = $this->espejoDeConductor();
        $u->forceFill(['sso_user_id' => null])->save();

        $this->withHeaders($this->delGateway())
            ->getJson('/api/treslog/driver/routes')
            ->assertStatus(403)
            ->assertJsonPath('error', 'forbidden');
    }

    /**
     * El `abort_if` de RouteController::show sigue comparando contra
     * `$request->user()->id`, y por el camino nuevo ese usuario lo pone
     * `ResolveDomainUser` con `auth()->setUser()`. Si esa linea dejara de correr,
     * esto seria un 500 por `null->id`, no un 403 (tarea 5.3).
     */
    public function test_pedir_la_ruta_de_otro_conductor_da_403_tambien_por_el_gateway(): void
    {
        $this->espejoDeConductor();
        $ajena = DeliveryRoute::factory()->create(['driver_id' => User::factory()->create()->id]);

        $this->withHeaders($this->delGateway())
            ->getJson("/api/treslog/driver/routes/{$ajena->id}")
            ->assertStatus(403);
    }
}
