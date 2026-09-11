<?php

namespace Tests\Feature\Sso;

use App\Http\Middleware\AuthenticateFromGateway;
use App\Http\Middleware\RequireSsoRole;
use App\Http\Middleware\ResolveDomainUser;
use App\Models\Incident;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RutaRegistrada;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Spec «Cobertura total de guardas de rol» (2-specs.md), tarea 8.9.
 *
 * Dos mitades. La ESTRUCTURAL recorre las rutas registradas —no una lista a
 * mano— y exige que toda ruta bajo `api/treslog/*` tenga una guarda de rol
 * (`sso.role`) o este en la lista cerrada de excepciones de pertenencia del
 * cliente, con el motivo escrito. Una ruta nueva sin clasificar hace fallar el
 * test con su nombre. La de COMPORTAMIENTO dispara peticiones reales: un
 * `treslog:customer` recibe 403 del contrato (con `request_id`) en cada ruta de
 * la consola de operaciones, y un `treslog:operations` NO recibe ese 403.
 */
class CoberturaDeGuardasDeRolTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Rutas del camino nuevo SIN `sso.role`, y por que esta bien que no lo
     * tengan: la autorizacion es de PERTENENCIA (la Policy o el controlador
     * comparan `user_id` con la persona), y eso el rol no lo puede decidir.
     */
    private function excepcionesDePertenencia(): array
    {
        return [
            'GET /api/treslog/me'                    => 'Quien soy. Cualquier identidad con espejo (Lote 7).',
            'GET /api/treslog/addresses'             => 'Sus direcciones: el controlador filtra por user_id.',
            'POST /api/treslog/addresses'            => 'Crea una direccion PROPIA.',
            'GET /api/treslog/addresses/{address}'   => 'authorizeOwner(): dueño o admin.',
            'PUT /api/treslog/addresses/{address}'   => 'Idem.',
            'DELETE /api/treslog/addresses/{address}' => 'Idem.',
            'POST /api/treslog/support'              => 'Abre un ticket PROPIO.',
            'GET /api/treslog/support'               => 'SupportTicketPolicy::viewAny (admin) o los propios.',
            'GET /api/treslog/support/{ticket}'      => 'SupportTicketPolicy::view: admin o dueño.',
            'PUT /api/treslog/support/{ticket}'      => 'SupportTicketPolicy::update: admin (@todo D7).',
            'GET /api/treslog/users/{id}'            => 'UserPolicy::view: admin o uno mismo.',
            'PUT /api/treslog/users/{id}'            => 'UserPolicy::update: admin o uno mismo.',
            'GET /api/treslog/quotes/pending-count'  => 'El controlador filtra por correo si no es de operaciones.',
            'GET /api/treslog/shipments'             => 'ShipmentPolicy + filtro por user_id en el controlador.',
            'POST /api/treslog/shipments'            => 'Crea un envio PROPIO (user_id = quien llama).',
            'GET /api/treslog/shipments/{id}'        => 'ShipmentPolicy::view: operaciones/admin o dueño.',
        ];
    }

    private function etiqueta(RutaRegistrada $r): string
    {
        return implode('|', array_diff($r->methods(), ['HEAD'])).' /'.$r->uri();
    }

    /** @return list<string> nombres de clase de la cadena real */
    private function cadena(RutaRegistrada $r): array
    {
        return array_values(array_filter(app('router')->gatherRouteMiddleware($r), 'is_string'));
    }

    /** @return list<RutaRegistrada> */
    private function rutasDelSso(): array
    {
        return array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            static fn (RutaRegistrada $r): bool => str_starts_with($r->uri(), 'api/treslog/')
        ));
    }

    public function test_toda_ruta_del_sso_tiene_guarda_de_rol_o_esta_en_la_lista_de_pertenencia(): void
    {
        $excepciones = $this->excepcionesDePertenencia();
        $sinGuarda   = [];
        $sinUsuario  = [];
        $vistas      = 0;

        foreach ($this->rutasDelSso() as $ruta) {
            $vistas++;
            $cadena   = $this->cadena($ruta);
            $etiqueta = $this->etiqueta($ruta);

            $this->assertContains(AuthenticateFromGateway::class, $cadena, "«{$etiqueta}» sin gateway.auth.");

            // `sso.role` viaja CON sus parametros en la cadena resuelta
            // (`...\RequireSsoRole:treslog:operations,treslog:admin`), por eso
            // no alcanza un in_array.
            $tieneRol     = (bool) array_filter($cadena, static fn (string $m): bool => str_starts_with($m, RequireSsoRole::class));
            $tieneUsuario = in_array(ResolveDomainUser::class, $cadena, true);

            if ($tieneRol) {
                continue;
            }

            if (! array_key_exists($etiqueta, $excepciones)) {
                $sinGuarda[] = $etiqueta;
                continue;
            }

            // Una excepcion de pertenencia SIN usuario resuelto no puede comparar
            // user_id con nadie: seria una ruta abierta a cualquier identidad.
            if (! $tieneUsuario) {
                $sinUsuario[] = $etiqueta;
            }
        }

        $this->assertGreaterThan(40, $vistas, 'Faltan rutas bajo api/treslog/: el dominio no esta montado (Lote 8).');

        $this->assertSame([], $sinGuarda, implode("\n", [
            '',
            'Rutas bajo `api/treslog/` sin `sso.role` y fuera de la lista de pertenencia:',
            '    '.implode("\n    ", $sinGuarda),
            '',
            'O le falta la guarda de rol (domain.php, grupo $operaciones), o es de',
            'pertenencia del cliente y va en excepcionesDePertenencia() CON EL MOTIVO.',
            '',
        ]));

        $this->assertSame([], $sinUsuario, "Excepciones de pertenencia sin gateway.user:\n    ".implode("\n    ", $sinUsuario));
    }

    public function test_la_lista_de_pertenencia_no_tiene_entradas_muertas(): void
    {
        $registradas = array_map(fn (RutaRegistrada $r) => $this->etiqueta($r), $this->rutasDelSso());

        foreach (array_keys($this->excepcionesDePertenencia()) as $etiqueta) {
            $this->assertContains($etiqueta, $registradas,
                "«{$etiqueta}» esta en la lista de excepciones pero ya no existe como ruta: borrala de la lista.");
        }
    }

    // ---- comportamiento -----------------------------------------------------

    private function delGateway(string $roles): array
    {
        return [
            'X-Auth-Gateway'  => 'myglobalhub-gateway',
            'X-User-Id'       => '4242',
            'X-User-Clerk-Id' => 'user_2abcClerk',
            'X-User-Email'    => 'persona@tr3slog.test',
            'X-User-Name'     => 'Persona De Prueba',
            'X-User-Roles'    => $roles,
        ];
    }

    /**
     * Las rutas de la consola de operaciones (domain.php, grupo $operaciones).
     *
     * Con filas REALES detras de `{incident}` y `{quote}`: el route-model binding
     * corre ANTES de la cadena de autenticacion (SubstituteBindings es del grupo
     * `api`, sso.role es de la ruta), y un id inexistente da 404 antes de que la
     * guarda pueda decir 403. El 404 no es una fuga —no revela nada que el 403 no
     * revele— pero no es lo que este test mide.
     */
    private function rutasDeOperaciones(User $u): array
    {
        $incidente = Incident::forceCreate(['driver_id' => $u->id, 'code' => 'INC-1', 'title' => 'Prueba']);
        $quote     = Quote::forceCreate(['origin' => 'A', 'destination' => 'B', 'client_name' => 'C', 'client_email' => 'c@x.test']);

        return [
            ['GET', '/api/treslog/alerts'],
            ['GET', '/api/treslog/drivers'],
            ['POST', '/api/treslog/drivers'],
            ['GET', '/api/treslog/incidents'],
            ['POST', '/api/treslog/incidents'],
            ['PATCH', "/api/treslog/incidents/{$incidente->id}/status"],
            ['GET', '/api/treslog/users'],
            ['GET', '/api/treslog/users/clients'],
            ['DELETE', '/api/treslog/users/1'],
            ['GET', '/api/treslog/quotes'],
            ['POST', '/api/treslog/quotes'],
            ['PATCH', "/api/treslog/quotes/{$quote->id}/status"],
            ['PUT', '/api/treslog/shipments/1'],
            ['DELETE', '/api/treslog/shipments/1'],
        ];
    }

    public function test_un_cliente_recibe_el_403_del_contrato_en_toda_la_consola_de_operaciones(): void
    {
        $u = User::factory()->create();
        $u->forceFill(['sso_user_id' => '4242'])->save();

        foreach ($this->rutasDeOperaciones($u) as [$metodo, $ruta]) {
            $this->withHeaders($this->delGateway('treslog:customer') + ['X-Request-Id' => 'sso-guardas-403'])
                ->json($metodo, $ruta)
                ->assertStatus(403, "«{$metodo} {$ruta}» dejo pasar a un cliente")
                ->assertJsonPath('error', 'forbidden')
                ->assertJsonPath('request_id', 'sso-guardas-403')
                ->assertJsonMissingPath('required');
        }
    }

    public function test_operaciones_no_recibe_el_403_de_rol_en_las_lecturas_de_la_consola(): void
    {
        $u = User::factory()->create();
        $u->forceFill(['sso_user_id' => '4242'])->save();

        foreach (['/api/treslog/alerts', '/api/treslog/drivers', '/api/treslog/incidents', '/api/treslog/quotes', '/api/treslog/users/clients'] as $ruta) {
            $this->withHeaders($this->delGateway('treslog:operations'))
                ->getJson($ruta)
                ->assertOk("«GET {$ruta}» le nego el acceso a operaciones");
        }
    }

    public function test_el_padron_completo_sigue_siendo_solo_de_admin_tambien_por_el_gateway(): void
    {
        $u = User::factory()->create();
        $u->forceFill(['sso_user_id' => '4242'])->save();

        // Pasa la guarda de operaciones y lo frena UserPolicy::viewAny: era solo
        // de admin (`users.view`) y sigue siendolo. Restringir no concede.
        $this->withHeaders($this->delGateway('treslog:operations'))->getJson('/api/treslog/users')->assertStatus(403);
        $this->withHeaders($this->delGateway('treslog:admin'))->getJson('/api/treslog/users')->assertOk();
    }
}
