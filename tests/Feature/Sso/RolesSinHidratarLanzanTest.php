<?php

namespace Tests\Feature\Sso;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Los DOS comportamientos de hasRole()/hasAnyRole()/isAdmin() desde el Lote 9
 * (en el Lote 8 eran tres; el tercero, «sin gateway -> lee role_user», murio
 * con la tabla):
 *
 *   (a) hidratada por ResolveDomainUser -> lee los roles del SSO;
 *   (b) NO hidratada, en una peticion del gateway O fuera de toda peticion
 *       (tinker, un job) -> LANZA.
 *
 * Si (a) leyera otra cosa, un admin del SSO veria el portal del cliente sin
 * un error en ningun log; si (b) devolviera false, un `User::find()->isAdmin()`
 * seria una denegacion fantasma.
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
    }

    // ---- (a) hidratada ------------------------------------------------------

    public function test_a_hidratada_lee_los_roles_del_sso_y_no_la_foto_de_la_fila(): void
    {
        $this->montarSondas();
        $u = $this->espejo();

        // Le dejamos una foto vieja que dice `admin` a proposito: la foto es para
        // listar, NO para autorizar. Si contara, el test de abajo (customer) daria admin.
        $u->forceFill(['sso_roles' => ['treslog:admin']])->save();

        $this->withHeaders($this->delGateway(['X-User-Roles' => 'treslog:admin,treslog:driver']))
            ->getJson('/api/treslog/_sonda/roles')
            ->assertOk()
            ->assertJson(['hidratado' => true, 'admin' => true, 'ops' => true, 'driver' => true, 'completo' => false]);

        $this->withHeaders($this->delGateway(['X-User-Roles' => 'treslog:customer']))
            ->getJson('/api/treslog/_sonda/roles')
            ->assertOk()
            ->assertJson(['hidratado' => true, 'admin' => false, 'ops' => false, 'driver' => false, 'completo' => true],
                'Con la foto diciendo admin y el SSO diciendo customer, gano la foto: la foto no autoriza.');
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

    // ---- (b) fuera de toda peticion -> tambien lanza ------------------------

    public function test_b_fuera_de_toda_peticion_del_gateway_tambien_lanza(): void
    {
        // Lo que ve un job en cola o `tinker`: una instancia traida por query.
        // Hasta el Lote 9 leia role_user; ya no hay de donde leer, y devolver
        // false seria mentir. Para listar por rol esta la foto `sso_roles`.
        $u = User::factory()->create();
        $u->forceFill(['sso_roles' => ['treslog:admin']])->save();

        $otraInstancia = User::find($u->id);
        $this->assertFalse($otraInstancia->tieneRolesSsoHidratados());
        $this->assertSame(['treslog:admin'], $otraInstancia->sso_roles, 'la foto se lee sin hidratar');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('no fue hidratado');
        $otraInstancia->isAdmin();
    }
}
