<?php

namespace Tests\Feature\Sso;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `GET /api/treslog/me` — la unica ruta del prefijo `treslog` sin guarda de rol.
 *
 * QUE PROTEGE ESTE ARCHIVO: que la web pueda saber quien entro sin preguntarselo
 * a `role_user`. Es el hallazgo 2 de la auditoria puesto en tests desde el otro
 * lado: para quien entra por el SSO esa tabla esta VACIA, asi que cualquier
 * codigo que lea roles de la base devuelve una lista vacia SIN ERROR — y el
 * sintoma no es un 500 ni una linea de log, es un admin viendo el portal del
 * cliente. Los dos tests de `role_user` de mas abajo son los que agarran eso:
 * uno con rol local y sin rol en la cabecera, el otro al reves.
 *
 * Las cabeceras se fabrican como en RutasConductorPorGatewayTest (`delGateway`):
 * el sello del gateway y las `X-User-*`, que es exactamente lo que NGINX entrega
 * al backend. No se usa `actingAs` a proposito — por este camino no hay guard de
 * Laravel que actuar, y un `actingAs` saltearia la cadena entera de middleware,
 * que es justo lo que estos tests vienen a comprobar.
 */
class MeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Una fila espejo: anclada por `sso_user_id` y NADA en `role_user`.
     *
     * Que no tenga roles locales no es un atajo del test, es el escenario real:
     * a la persona la da de alta el SSO y su fila en TR3SLOG es un espejo con el
     * ancla y los datos de dominio.
     */
    private function espejo(string $ssoUserId = '4242', array $atributos = []): User
    {
        $u = User::factory()->create($atributos + [
            'company' => 'Importadora Del Sur',
            'phone'   => '+1 809 555 0100',
        ]);

        // `sso_user_id` esta FUERA de $fillable a proposito (es el ancla de la
        // identidad): `fill()` lo descartaria en silencio y el test pasaria a
        // probar otra cosa.
        $u->forceFill(['sso_user_id' => $ssoUserId])->save();

        return $u->fresh();
    }

    /** Cabeceras de una peticion que SI paso por el gateway del SSO. */
    private function delGateway(array $extra = []): array
    {
        return array_merge([
            'X-Auth-Gateway'  => 'myglobalhub-gateway',
            'X-User-Id'       => '4242',
            'X-User-Clerk-Id' => 'user_2abcClerk',
            'X-User-Email'    => 'ana@tr3slog.test',
            'X-User-Name'     => 'Ana Perez',
            'X-User-Roles'    => 'treslog:customer',
        ], $extra);
    }

    // =======================================================================
    //  200: la identidad completa, con los roles que emitio el SSO
    // =======================================================================

    public function test_un_admin_recibe_su_identidad_y_alcanza_la_consola(): void
    {
        $u = $this->espejo();

        $this->withHeaders($this->delGateway(['X-User-Roles' => 'treslog:admin']))
            ->getJson('/api/treslog/me')
            ->assertOk()
            // El id LOCAL, no el del SSO: es el que entienden las nueve FKs del
            // dominio y con el que la web pide sus envios.
            ->assertJsonPath('data.id', $u->id)
            ->assertJsonPath('data.sso_user_id', '4242')
            ->assertJsonPath('data.name', 'Ana Perez')
            ->assertJsonPath('data.email', 'ana@tr3slog.test')
            ->assertJsonPath('data.company', 'Importadora Del Sur')
            ->assertJsonPath('data.phone', '+1 809 555 0100')
            ->assertJsonPath('data.roles', ['treslog:admin'])
            ->assertJsonPath('data.is_admin', true);
    }

    public function test_un_cliente_recibe_su_identidad_y_NO_alcanza_la_consola(): void
    {
        $this->espejo();

        $this->withHeaders($this->delGateway(['X-User-Roles' => 'treslog:customer']))
            ->getJson('/api/treslog/me')
            ->assertOk()
            ->assertJsonPath('data.roles', ['treslog:customer'])
            // El dato que la web usa para elegir entre el menu administrativo y
            // el del cliente. Si esto se pusiera en `true`, un cliente veria la
            // consola entera — que no podria USAR (`sso.role` sigue en cada
            // ruta), pero se enteraria de que existe.
            ->assertJsonPath('data.is_admin', false);
    }

    /**
     * `operations` tambien, y no es un detalle: la web de hoy decide el menu con
     * `['admin','operations'].includes(r.name)` (AppShell.jsx:33). Si `is_admin`
     * contestara solo por `admin`, el equipo de operaciones —que es el que usa la
     * consola todos los dias— se quedaria con el portal del cliente.
     */
    public function test_operaciones_tambien_alcanza_la_consola(): void
    {
        $this->espejo();

        $this->withHeaders($this->delGateway(['X-User-Roles' => 'treslog:operations']))
            ->getJson('/api/treslog/me')
            ->assertOk()
            ->assertJsonPath('data.roles', ['treslog:operations'])
            ->assertJsonPath('data.is_admin', true);
    }

    /**
     * Varios roles de TR3SLOG a la vez: se devuelven TODOS, en el orden en que
     * vinieron, y alcanza uno para la consola.
     */
    public function test_varios_roles_de_treslog_se_devuelven_todos(): void
    {
        $this->espejo();

        $this->withHeaders($this->delGateway(['X-User-Roles' => 'treslog:driver,treslog:operations']))
            ->getJson('/api/treslog/me')
            ->assertOk()
            ->assertJsonPath('data.roles', ['treslog:driver', 'treslog:operations'])
            ->assertJsonPath('data.is_admin', true);
    }

    // =======================================================================
    //  Los roles de OTRO inquilino no son roles de TR3SLOG
    // =======================================================================

    /**
     * La misma persona puede tener roles en MSH, en la tienda y en la plataforma.
     * Por `X-User-Roles` deberian llegar SOLO los de `treslog` (el SSO filtra por
     * alcance de aplicacion), y el filtro de aca es defensa en profundidad: si
     * ese filtro fallara, o si alguien alcanzara el backend sin pasar por el
     * gateway —que hoy es posible, ver SuperficieAbiertaSelloForjableTest—, la
     * web pintaria roles ajenos como si fueran suyos.
     *
     * `Super Admin` entra en la misma bolsa a proposito: es rol de PLATAFORMA y
     * no equivale a ningun `treslog:*` (R4 del plan).
     */
    public function test_los_roles_de_otras_aplicaciones_no_aparecen(): void
    {
        $this->espejo();

        $r = $this->withHeaders($this->delGateway([
            'X-User-Roles' => 'msh:user,treslog:driver,tienda:vendedor,Super Admin',
        ]))->getJson('/api/treslog/me')->assertOk();

        $r->assertJsonPath('data.roles', ['treslog:driver'])
            ->assertJsonPath('data.is_admin', false);

        // Y que no se cuelen por ningun otro lado del sobre.
        $cuerpo = $r->getContent();
        $this->assertStringNotContainsString('msh:user', $cuerpo);
        $this->assertStringNotContainsString('tienda:vendedor', $cuerpo);
        $this->assertStringNotContainsString('Super Admin', $cuerpo);
    }

    /**
     * Sin `X-User-Roles` (NGINX omite el `proxy_set_header` de valor vacio, o sea
     * que asi llega alguien sin ningun rol de TR3SLOG): lista vacia, 200, y sin
     * consola. No es un error — es una persona dada de alta a la que todavia no
     * le asignaron rol.
     */
    public function test_sin_roles_responde_200_con_lista_vacia(): void
    {
        $this->espejo();

        $cabeceras = $this->delGateway();
        unset($cabeceras['X-User-Roles']);

        $this->withHeaders($cabeceras)
            ->getJson('/api/treslog/me')
            ->assertOk()
            ->assertJsonPath('data.roles', [])
            ->assertJsonPath('data.is_admin', false);
    }

    // =======================================================================
    //  Los roles salen del SSO, NUNCA de `role_user` (hallazgo 2)
    // =======================================================================

    /**
     * Rol local de admin en `role_user`, y en la cabecera un cliente. Si esta
     * ruta leyera la tabla —`$user->load('roles')`, que es lo que hace hoy
     * `AuthController::me`— devolveria `admin` y le abriria la consola a alguien
     * al que el SSO no le dio ese rol. La fuente de verdad es la cabecera.
     */
    public function test_un_rol_local_en_role_user_NO_concede_la_consola(): void
    {
        $u = $this->espejo();
        // `display_name` es NOT NULL en la tabla (create_roles_table) y no tiene
        // default: sin el, el fixture explota antes de probar nada.
        $u->roles()->attach(Role::create(['name' => 'admin', 'display_name' => 'Administrador'])->id);

        $this->assertTrue($u->fresh()->hasRole('admin'),
            'El fixture dejo de representar el caso: el usuario tiene que tener el rol LOCAL.');

        $this->withHeaders($this->delGateway(['X-User-Roles' => 'treslog:customer']))
            ->getJson('/api/treslog/me')
            ->assertOk()
            ->assertJsonPath('data.roles', ['treslog:customer'])
            ->assertJsonPath('data.is_admin', false);
    }

    /**
     * El complemento, y el caso REAL de todos los dias: un admin del SSO cuya
     * fila espejo no tiene NADA en `role_user`. Si esta ruta leyera la tabla,
     * este 200 vendria con `roles: []` y la web lo mandaria al portal del
     * cliente sin una sola linea de log que lo explique.
     */
    public function test_un_admin_del_SSO_sin_fila_en_role_user_alcanza_la_consola(): void
    {
        $u = $this->espejo();

        $this->assertFalse($u->hasRole('admin'),
            'El fixture dejo de representar el caso: un espejo del SSO no tiene roles locales.');

        $this->withHeaders($this->delGateway(['X-User-Roles' => 'treslog:admin']))
            ->getJson('/api/treslog/me')
            ->assertOk()
            ->assertJsonPath('data.is_admin', true);
    }

    // =======================================================================
    //  401 y 403: las dos formas de no entrar, que son distintas
    // =======================================================================

    public function test_sin_cabeceras_del_gateway_responde_401(): void
    {
        $this->espejo();

        $this->getJson('/api/treslog/me')
            ->assertStatus(401)
            ->assertJsonPath('error', 'unauthenticated');
    }

    public function test_con_el_sello_forjado_responde_401(): void
    {
        $this->espejo();

        $this->withHeaders($this->delGateway(['X-Auth-Gateway' => 'cualquier-otra-cosa']))
            ->getJson('/api/treslog/me')
            ->assertStatus(401)
            ->assertJsonPath('error', 'unauthenticated');
    }

    /**
     * EL 403 QUE LA WEB TIENE QUE SABER MOSTRAR.
     *
     * Identidad valida del SSO, sello valido, y ninguna fila espejo en `users`:
     * es el estado NORMAL de cualquiera que se registre en el ecosistema
     * mientras la migracion de cuentas siga fuera de alcance (R10). Para la
     * persona no significa "no tenes permiso", significa «tu cuenta del SSO
     * todavia no esta habilitada en TR3SLOG», y loguearse de nuevo no lo arregla
     * nunca. Por eso 403 y no 401, y por eso el `request_id`: es lo unico con lo
     * que el soporte encuentra esta peticion en los tres logs.
     */
    public function test_una_identidad_sin_fila_espejo_responde_403_con_request_id(): void
    {
        // Existe la persona en el SSO (cabeceras validas) y NO hay espejo local.
        User::factory()->create();

        $this->withHeaders($this->delGateway(['X-Request-Id' => 'sso-no-habilitada']))
            ->getJson('/api/treslog/me')
            ->assertStatus(403)
            ->assertJsonPath('error', 'forbidden')
            ->assertJsonPath('request_id', 'sso-no-habilitada');
    }

    /**
     * El espejo se resuelve por `sso_user_id` y NUNCA por email. Misma persona en
     * el SSO, mismo email que una cuenta local vieja, y sin ancla: 403. Un
     * vinculo automatico por correo es la forma normal de robar una cuenta cuando
     * el proveedor de identidad y el dominio no comparten el registro.
     */
    public function test_el_email_coincidente_no_alcanza_para_resolver_identidad(): void
    {
        User::factory()->create(['email' => 'ana@tr3slog.test']);

        $this->withHeaders($this->delGateway())
            ->getJson('/api/treslog/me')
            ->assertStatus(403)
            ->assertJsonPath('error', 'forbidden');
    }

    // =======================================================================
    //  El sobre es una lista blanca, no la fila entera
    // =======================================================================

    /**
     * `data` se arma clave por clave a proposito. Este test es el que se pone
     * rojo el dia que alguien lo cambie por `$user->toArray()` "para no repetir":
     * `users` tiene `password`, `remember_token`, `reset_token` y
     * `reset_token_expires`, y aunque los dos primeros esten en `$hidden`, los
     * dos tokens de reseteo NO lo estan. Un endpoint de identidad que devuelve un
     * token de reseteo de contraseña es una toma de cuenta servida en JSON.
     */
    public function test_el_sobre_no_filtra_credenciales(): void
    {
        $u = $this->espejo();
        $u->forceFill([
            'reset_token'         => 'token-de-reseteo-secreto',
            'reset_token_expires' => now()->addHour(),
        ])->save();

        $cuerpo = $this->withHeaders($this->delGateway())
            ->getJson('/api/treslog/me')
            ->assertOk()
            ->getContent();

        foreach (['password', 'remember_token', 'reset_token', 'token-de-reseteo-secreto'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $cuerpo,
                "El sobre de `/me` filtro «{$prohibido}»: `data` se arma con una lista blanca de claves, no con toArray().");
        }
    }

    /**
     * La ruta NO lleva `sso.role`, y eso es una decision, no un olvido: cualquier
     * identidad con espejo tiene que poder preguntar quien es. Este test la
     * congela desde el comportamiento —los cuatro roles entran— para que si
     * alguien "endurece" la ruta agregandole una guarda de rol, se entere de que
     * esta apagando el login de tres de los cuatro perfiles.
     */
    public function test_los_cuatro_roles_de_treslog_pueden_preguntar_quien_son(): void
    {
        $this->espejo();

        foreach (config('sso.roles') as $corto => $completo) {
            $this->withHeaders($this->delGateway(['X-User-Roles' => $completo]))
                ->getJson('/api/treslog/me')
                ->assertOk("El rol «{$corto}» ({$completo}) no pudo preguntar quien es.")
                ->assertJsonPath('data.roles', [$completo]);
        }
    }
}
