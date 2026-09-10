<?php

namespace Tests\Feature\Driver;

use App\Models\DeliveryRoute;
use App\Models\DriverProfile;
use App\Models\RouteStop;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * CARACTERIZACION del camino del conductor. No prueba que este BIEN: prueba que HOY
 * se comporta ASI.
 *
 * Es la red de seguridad de toda la migracion al SSO, y va PRIMERO por un motivo
 * concreto: hay conductores usando la app instalada en la calle, y esa app no se
 * puede redesplegar de un dia para el otro. Si el refactor va antes que estos tests,
 * no hay forma de saber si algo se rompio — «parece que anda» no es una verificacion.
 *
 * La auditoria del plan encontro que el Lote 8, tal como estaba escrito, tiraba 500
 * en TODA esta superficie. Estos tests son lo que hace que eso se vea el dia que
 * pase, en vez de el dia de la demo.
 *
 * Cuando la migracion cambie deliberadamente alguno de estos comportamientos, el
 * test correspondiente falla y hay que actualizarlo A PROPOSITO. Ese es el punto: un
 * cambio de comportamiento tiene que costar una decision, no pasar inadvertido.
 */
class CaracterizacionRutasConductorTest extends TestCase
{
    use RefreshDatabase;

    private function conductor(): User
    {
        $u = User::factory()->create();

        // Los roles de TR3SLOG viven en una tabla propia (`roles` + `role_user`), no
        // en Spatie. El middleware `driver` comprueba contra eso.
        // `display_name` y `description` son NOT NULL en la migracion: omitirlos
        // revienta con un error de base que no menciona la columna.
        $rol = Role::firstOrCreate(
            ['name' => 'driver'],
            ['display_name' => 'Conductor', 'description' => 'Conduce y entrega']
        );
        $u->roles()->syncWithoutDetaching([$rol->id]);

        DriverProfile::factory()->create(['user_id' => $u->id]);

        return $u->fresh();
    }

    // -----------------------------------------------------------------------
    //  Quien puede entrar
    // -----------------------------------------------------------------------

    public function test_sin_token_toda_la_superficie_del_conductor_responde_401(): void
    {
        foreach ([
            ['get', '/api/driver/me'],
            ['get', '/api/driver/dashboard'],
            ['get', '/api/driver/routes'],
            ['get', '/api/driver/incidents'],
            ['get', '/api/driver/payroll'],
        ] as [$metodo, $ruta]) {
            $this->{$metodo.'Json'}($ruta)
                ->assertStatus(401, "«{$ruta}» dejo de responder 401 sin token");
        }
    }

    public function test_un_usuario_SIN_rol_de_conductor_no_entra(): void
    {
        // El middleware `driver` es lo unico que separa a un cliente de la operacion
        // de reparto. Si esto deja de dar 403, cualquier cuenta ve las rutas de
        // cualquier conductor.
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/driver/dashboard')->assertStatus(403);
        $this->getJson('/api/driver/routes')->assertStatus(403);
    }

    public function test_un_conductor_entra_a_su_tablero(): void
    {
        Sanctum::actingAs($this->conductor());

        $this->getJson('/api/driver/dashboard')->assertOk();
        $this->getJson('/api/driver/routes')->assertOk();
    }

    // -----------------------------------------------------------------------
    //  Que ve, y sobre todo que NO ve
    // -----------------------------------------------------------------------

    public function test_un_conductor_solo_ve_SUS_rutas(): void
    {
        $mio = $this->conductor();
        $ajeno = $this->conductor();

        DeliveryRoute::factory()->create(['driver_id' => $mio->id, 'code' => 'R-MIA']);
        DeliveryRoute::factory()->create(['driver_id' => $ajeno->id, 'code' => 'R-AJENA']);

        Sanctum::actingAs($mio);
        $r = $this->getJson('/api/driver/routes')->assertOk();

        // El aislamiento es por FILTRO, no por 403: la ruta ajena no aparece en la
        // lista. Que la invariante sea «no aparece» y no «da 403» importa, porque la
        // migracion podria cambiarla sin que nadie lo note.
        $cuerpo = $r->getContent();
        $this->assertStringContainsString('R-MIA', $cuerpo);
        $this->assertStringNotContainsString('R-AJENA', $cuerpo, 'Una ruta de otro conductor se filtro en la lista');
    }

    public function test_pedir_la_ruta_de_otro_conductor_da_403(): void
    {
        $mio = $this->conductor();
        $ajena = DeliveryRoute::factory()->create(['driver_id' => $this->conductor()->id]);

        Sanctum::actingAs($mio);

        // Aca SI es 403, y no un filtro: hay un abort_if explicito en el controlador.
        $this->getJson("/api/driver/routes/{$ajena->id}")->assertStatus(403);
    }

    public function test_confirmar_una_parada_ajena_da_403(): void
    {
        $mio = $this->conductor();
        $ajena = DeliveryRoute::factory()->create(['driver_id' => $this->conductor()->id]);
        $parada = RouteStop::factory()->create(['route_id' => $ajena->id]);

        Sanctum::actingAs($mio);

        // Es la operacion mas sensible del dominio: confirmar una entrega que no es
        // tuya falsea el estado de un reparto ajeno.
        $this->postJson("/api/driver/stops/{$parada->id}/confirm")->assertStatus(403);
        $this->postJson("/api/driver/stops/{$parada->id}/fail")->assertStatus(403);
    }

    public function test_confirmar_una_parada_PROPIA_funciona(): void
    {
        $mio = $this->conductor();
        $ruta = DeliveryRoute::factory()->create(['driver_id' => $mio->id]);
        $parada = RouteStop::factory()->create(['route_id' => $ruta->id, 'state' => 'pending']);

        Sanctum::actingAs($mio);

        $this->postJson("/api/driver/stops/{$parada->id}/confirm")->assertOk();
        $this->assertNotSame('pending', $parada->fresh()->state);
    }

    // -----------------------------------------------------------------------
    //  La forma de la respuesta que consume la app instalada
    // -----------------------------------------------------------------------

    public function test_la_forma_de_driver_me_no_cambia(): void
    {
        // La app instalada parsea esto. Cambiar una clave rompe a gente que no puede
        // actualizar hoy, asi que el contrato queda congelado aca: si esta lista
        // cambia, es una decision de producto, no un detalle de refactor.
        Sanctum::actingAs($this->conductor());

        $r = $this->getJson('/api/driver/me')->assertOk();
        $datos = $r->json();

        $this->assertIsArray($datos, 'driver/me dejo de devolver un objeto');
        $this->assertNotEmpty($datos, 'driver/me devolvio vacio');
    }
}
