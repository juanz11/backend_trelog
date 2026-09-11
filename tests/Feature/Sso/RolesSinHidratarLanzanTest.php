<?php

namespace Tests\Feature\Sso;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Los TRES comportamientos de hasRole()/hasAnyRole()/isAdmin() (Lote 8, D8.4):
 *
 *   (a) hidratada por ResolveDomainUser -> lee los roles del SSO;
 *   (b) NO hidratada en una peticion que entro por el gateway -> LANZA;
 *   (c) NO hidratada y sin gateway (Sanctum, tinker, jobs) -> lee role_user.
 *
 * Cada caso puede fallar solo: si (a) leyera role_user, un admin del SSO veria
 * el portal del cliente sin un error en ningun log (hallazgo 2); si (b)
 * devolviera false, un `User::find()->isAdmin()` seria una denegacion fantasma;
 * si (c) lanzara, los conductores con la app instalada se quedarian sin backend.
 */
class RolesSinHidratarLanzanTest extends TestCase
{
    use RefreshDatabase;

    private function delGateway(array $extra = []): array
    {
        return array_merge([
            'X-Auth-Gateway'  => 'myglobalhub-gateway',
            'X-User-Id'       => '4242',
            'X-User-Clerk-Id' => 'user_2abcClerk',
            'X-User-Email'    => 'persona@tr3slog.test',
            'X-User-Name'     => 'Persona De Prueba',
            'X-User-Roles'    => 'treslog:customer',
        ], $extra);
    }

    private function espejo(string $ssoUserId = '4242'): User
    {
        $u = User::factory()->create();
        $u->forceFill(['sso_user_id' => $ssoUserId])->save();

        return $u->fresh();
    }

    /**
     * Rutas de sonda, SOLO para este test. Las dos del gateway responden lo que
     * dicen los metodos de rol sobre distintas instancias de la misma persona.
     */
    private function montarSondas(): void
    {
        Route::prefix('api/treslog')->middleware(['gateway.auth', 'gateway.user'])->group(function () {
            Route::get('/_sonda/roles', fn (Request $r) => [
                'hidratado' => $r->user()->tieneRolesSsoHidratados(),
                'admin'     => $r->user()->isAdmin(),
                'ops'       => $r->user()->hasAnyRole(['operations', 'admin']),
                'driver'    => $r->user()->hasRole('driver'),
                'completo'  => $r->user()->hasRole('treslog:customer'),
            ]);

            // La trampa que D8.4 existe para agarrar: OTRA instancia de la misma fila.
            Route::get('/_sonda/find', fn (Request $r) => ['admin' => User::find($r->user()->id)->isAdmin()]);
        });

        Route::prefix('api')->middleware('auth:sanctum')->group(function () {
            Route::get('/_sonda/legacy', fn (Request $r) => [
                'hidratado' => $r->user()->tieneRolesSsoHidratados(),
                'admin'     => $r->user()->isAdmin(),
                'ops'       => $r->user()->hasAnyRole(['admin', 'operations']),
            ]);
        });
    }

    // ---- (a) hidratada ------------------------------------------------------

    public function test_a_hidratada_lee_los_roles_del_sso_y_no_role_user(): void
    {
        $this->montarSondas();
        $u = $this->espejo();

        // Le damos `admin` en role_user a proposito: por el camino del SSO NO
        // tiene que contar. Si contara, el test de abajo (customer) daria admin.
        $u->roles()->attach(Role::create(['name' => 'admin', 'display_name' => 'Admin']));

        $this->withHeaders($this->delGateway(['X-User-Roles' => 'treslog:admin,treslog:driver']))
            ->getJson('/api/treslog/_sonda/roles')
            ->assertOk()
            ->assertJson(['hidratado' => true, 'admin' => true, 'ops' => true, 'driver' => true, 'completo' => false]);

        $this->withHeaders($this->delGateway(['X-User-Roles' => 'treslog:customer']))
            ->getJson('/api/treslog/_sonda/roles')
            ->assertOk()
            ->assertJson(['hidratado' => true, 'admin' => false, 'ops' => false, 'driver' => false, 'completo' => true],
                'Con role_user diciendo admin y el SSO diciendo customer, gano role_user: el hallazgo 2 al reves.');
    }

    public function test_a_un_rol_de_otra_aplicacion_no_cuenta_aunque_llegue(): void
    {
        $this->montarSondas();
        $this->espejo();

        $this->withHeaders($this->delGateway(['X-User-Roles' => 'msh:admin,tienda:admin']))
            ->getJson('/api/treslog/_sonda/roles')
            ->assertOk()
            ->assertJson(['admin' => false, 'ops' => false]);
    }

    // ---- (b) no hidratada, por el gateway -> lanza ---------------------------

    public function test_b_user_find_dentro_de_una_peticion_del_gateway_lanza(): void
    {
        $this->montarSondas();
        $this->espejo();
        $this->withoutExceptionHandling();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('no fue hidratado');

        $this->withHeaders($this->delGateway(['X-User-Roles' => 'treslog:admin']))
            ->getJson('/api/treslog/_sonda/find');
    }

    // ---- (c) sin gateway -> role_user, como siempre -------------------------

    public function test_c_por_sanctum_sigue_leyendo_role_user(): void
    {
        $this->montarSondas();
        $u = User::factory()->create();
        Sanctum::actingAs($u);

        $this->getJson('/api/_sonda/legacy')
            ->assertOk()
            ->assertJson(['hidratado' => false, 'admin' => false, 'ops' => false]);

        $u->roles()->attach(Role::create(['name' => 'operations', 'display_name' => 'Ops']));

        $this->getJson('/api/_sonda/legacy')
            ->assertOk()
            ->assertJson(['hidratado' => false, 'admin' => false, 'ops' => true]);
    }

    public function test_c_fuera_de_toda_peticion_del_gateway_no_lanza_y_lee_role_user(): void
    {
        // Lo que ve un job en cola o `tinker`: una instancia traida por query,
        // sin ninguna identidad de gateway en el request de la aplicacion.
        $u = User::factory()->create();
        $u->roles()->attach(Role::create(['name' => 'admin', 'display_name' => 'Admin']));

        $otraInstancia = User::find($u->id);

        $this->assertFalse($otraInstancia->tieneRolesSsoHidratados());
        $this->assertTrue($otraInstancia->isAdmin());
        $this->assertTrue($otraInstancia->hasAnyRole(['operations', 'admin']));
        $this->assertFalse($otraInstancia->hasRole('driver'));
    }
}
