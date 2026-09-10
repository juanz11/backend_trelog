<?php

namespace Tests\Feature\Sso;

use App\Models\Role;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Los cuatro agujeros de `1-proposal.md §1`, cerrados y congelados.
 *
 * ESTOS NO SON TESTS DE CARACTERIZACION. Los del Lote 1 congelan lo que el
 * sistema HACE; estos congelan un cambio DELIBERADO de comportamiento, y por eso
 * cada uno lleva escrito el estado anterior, medido en este repo con una
 * peticion real antes de tocar nada:
 *
 *   POST /api/register {"role":"admin"}  -> 201 y `isAdmin()` daba TRUE
 *   GET  /api/roles       (customer)     -> 200
 *   POST /api/permissions (customer)     -> 201, permiso creado
 *   GET  /api/invitations/pending (nada) -> 200 con email y nombre
 *   POST /api/users       (admin real)   -> 403  <- este NO era un agujero
 *
 * Si alguno de estos tests se pone en verde "solo", o se borra para que la suite
 * pase, el agujero volvio. Son la unica prueba de que se cerraron.
 */
class CierreDeAgujerosTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    //  Utilidades
    // -----------------------------------------------------------------------

    /**
     * Los roles de TR3SLOG viven en tablas propias (`roles` + `role_user`), no en
     * Spatie. `display_name` y `description` son NOT NULL en la migracion:
     * omitirlos revienta con un error de base que no menciona la columna.
     */
    private function rol(string $nombre): Role
    {
        return Role::firstOrCreate(
            ['name' => $nombre],
            ['display_name' => ucfirst($nombre), 'description' => "Rol {$nombre}"]
        );
    }

    /** Usuario del camino VIEJO: fila local + rol local + token de Sanctum. */
    private function conTokenSanctum(string $rol): string
    {
        $u = User::factory()->create();
        $u->roles()->syncWithoutDetaching([$this->rol($rol)->id]);

        // Bearer REAL, no `Sanctum::actingAs`. La diferencia importa: actingAs
        // sustituye el guard por defecto y hace pasar como autenticada una
        // peticion a una ruta que NO tiene `auth:sanctum` montado. Con eso se
        // puede "demostrar" que una ruta esta protegida cuando en produccion no
        // lo esta. Un Bearer de verdad recorre la misma cadena que el cliente.
        return $u->fresh()->createToken('test')->plainTextToken;
    }

    /** Cabeceras de una peticion que SI paso por el gateway del SSO. */
    private function delGateway(array $extra = []): array
    {
        return array_merge([
            'X-Auth-Gateway'  => 'myglobalhub-gateway',
            'X-User-Id'       => '4242',
            'X-User-Clerk-Id' => 'user_2abcClerk',
            'X-User-Email'    => 'jefa@tr3slog.test',
            'X-User-Name'     => 'Jefa de Operaciones',
            'X-User-Roles'    => 'treslog:admin',
        ], $extra);
    }

    // =======================================================================
    //  4.5 — Agujero 1: auto-escalacion a admin desde el alta publica
    // =======================================================================

    public function test_register_con_role_admin_ya_no_produce_un_admin(): void
    {
        $this->rol('admin');
        $this->rol('customer');

        // Exactamente la peticion que antes creaba un administrador sin login.
        $this->postJson('/api/register', [
            'name'     => 'Intrusa',
            'email'    => 'intrusa@ejemplo.test',
            'password' => 'Abcdef1!',
            'role'     => 'admin',
        ])->assertStatus(201);

        $creada = User::where('email', 'intrusa@ejemplo.test')->firstOrFail();

        $this->assertFalse(
            $creada->isAdmin(),
            'El alta publica volvio a conceder el rol que pide el cliente: auto-escalacion.'
        );
        $this->assertTrue(
            $creada->hasRole('customer'),
            'El alta publica tiene que dejar `customer`, que es el default_role de la app en el SSO.'
        );
    }

    /**
     * La contracara del test de arriba, y la que agarra el arreglo A MEDIAS.
     *
     * La tarea 4.3 pedia "quitar el campo role del validate". Si se hace SOLO
     * eso, este test sigue rojo: el validador de Laravel no filtra el input, y
     * `$request->role` sigue devolviendo lo que mando el cliente. La regla que
     * se saca es cosmetica; lo que cierra el agujero es dejar de leer el campo.
     */
    public function test_ningun_rol_pedido_por_el_cliente_llega_a_role_user(): void
    {
        foreach (['admin', 'operations', 'driver'] as $pretendido) {
            $this->rol($pretendido);
        }
        $this->rol('customer');

        foreach (['admin', 'operations', 'driver'] as $i => $pretendido) {
            $email = "prueba{$i}@ejemplo.test";

            $this->postJson('/api/register', [
                'name'     => 'Prueba',
                'email'    => $email,
                'password' => 'Abcdef1!',
                'role'     => $pretendido,
            ])->assertStatus(201);

            $u = User::where('email', $email)->firstOrFail();

            $this->assertFalse(
                $u->hasRole($pretendido),
                "El alta publica concedio «{$pretendido}» porque el cliente lo pidio."
            );
            $this->assertSame(
                ['customer'],
                $u->roles()->pluck('name')->all(),
                'El alta publica tiene que dejar exactamente un rol, y ser `customer`.'
            );
        }
    }

    // =======================================================================
    //  4.6 — Agujero 2: roles/*, permissions/* y zones/* sin guarda
    // =======================================================================

    /**
     * El agujero medido: un `customer` autoregistrado entraba a 200 y podia
     * reescribir los permisos del rol `admin`.
     */
    public function test_un_customer_autenticado_ya_no_administra_roles_permisos_ni_zonas(): void
    {
        $token = $this->conTokenSanctum('customer');

        foreach ([
            ['get',  '/api/roles'],
            ['post', '/api/roles'],
            ['get',  '/api/permissions'],
            ['post', '/api/permissions'],
            ['get',  '/api/zones'],
            ['post', '/api/zones'],
            ['get',  '/api/invitations/pending'],
        ] as [$metodo, $ruta]) {
            $this->withHeader('Authorization', 'Bearer '.$token)
                ->{$metodo.'Json'}($ruta)
                ->assertStatus(403, "«{$metodo} {$ruta}» volvio a estar abierta a cualquier autenticado");
        }
    }

    /**
     * La otra mitad, y la que evita el "arreglo" que rompe a quien si tenia
     * derecho: el admin del camino viejo NO pierde la administracion. Sin este
     * test, cerrar el agujero apagando el endpoint pasaria por arreglo.
     */
    public function test_el_admin_del_camino_viejo_sigue_administrando(): void
    {
        $token = $this->conTokenSanctum('admin');

        foreach (['/api/roles', '/api/permissions', '/api/zones', '/api/invitations/pending'] as $ruta) {
            $this->withHeader('Authorization', 'Bearer '.$token)
                ->getJson($ruta)
                ->assertStatus(200, "«{$ruta}» dejo de funcionar para el admin: se rompio a quien si tenia derecho");
        }
    }

    public function test_sin_token_la_superficie_de_administracion_responde_401(): void
    {
        foreach (['/api/roles', '/api/permissions', '/api/zones', '/api/invitations/pending'] as $ruta) {
            $this->getJson($ruta)->assertStatus(401, "«{$ruta}» sigue respondiendo sin autenticacion");
        }
    }

    // -----------------------------------------------------------------------
    //  Lo mismo, por el camino del SSO
    // -----------------------------------------------------------------------

    public function test_por_el_camino_sso_el_rol_treslog_customer_no_alcanza(): void
    {
        foreach (['/api/treslog/roles', '/api/treslog/permissions', '/api/treslog/zones'] as $ruta) {
            $this->withHeaders($this->delGateway(['X-User-Roles' => 'treslog:customer']))
                ->getJson($ruta)
                ->assertStatus(403, "«{$ruta}» acepto un treslog:customer")
                ->assertJsonPath('error', 'forbidden');
        }
    }

    /**
     * NGINX omite un `proxy_set_header` de valor vacio, asi que una persona sin
     * ningun rol de TR3SLOG llega SIN la cabecera. No es un error: es una
     * peticion valida de alguien que no tiene permiso.
     */
    public function test_por_el_camino_sso_sin_cabecera_de_roles_no_alcanza(): void
    {
        $cabeceras = $this->delGateway();
        unset($cabeceras['X-User-Roles']);

        $this->withHeaders($cabeceras)
            ->getJson('/api/treslog/roles')
            ->assertStatus(403)
            ->assertJsonPath('error', 'forbidden');
    }

    /**
     * Control POSITIVO, y no es decorativo: sin el, los cinco 403 de arriba
     * podrian venir de una ruta mal montada o de un 403 que da siempre, y la
     * suite estaria verde sin probar absolutamente nada sobre los roles.
     */
    public function test_por_el_camino_sso_treslog_admin_si_entra(): void
    {
        foreach (['/api/treslog/roles', '/api/treslog/permissions', '/api/treslog/zones'] as $ruta) {
            $this->withHeaders($this->delGateway())
                ->getJson($ruta)
                ->assertStatus(200, "«{$ruta}» le nego el paso a un treslog:admin legitimo");
        }
    }

    // =======================================================================
    //  4.9 / 4.10 — Los dos roles que NO alcanzan, y por que cada uno
    // =======================================================================

    /**
     * 4.9 — `Super Admin` es rol de PLATAFORMA del SSO: viaja a todas las
     * aplicaciones del ecosistema. Si alcanzara para administrar TR3SLOG, el
     * admin de cualquier otra aplicacion administraria esta.
     *
     * El modo de falla aca es conceder de mas, que es el unico que no se nota:
     * nadie abre un ticket porque le dejaron entrar.
     */
    public function test_super_admin_no_alcanza_para_una_ruta_que_exige_treslog_admin(): void
    {
        $this->withHeaders($this->delGateway(['X-User-Roles' => 'Super Admin']))
            ->getJson('/api/treslog/roles')
            ->assertStatus(403)
            ->assertJsonPath('error', 'forbidden');
    }

    /**
     * 4.10 — Un rol de OTRO inquilino. Que `tienda:vendedor` no alcance es todo
     * el punto del modelo multi-aplicacion: el prefijo no es decoracion, es el
     * limite entre inquilinos.
     *
     * Se prueba tambien `admin` a secas y `treslog:admin` con otro prefijo, que
     * son las dos formas realistas de equivocarse escribiendo el rol.
     */
    public function test_roles_de_otro_inquilino_o_sin_prefijo_no_alcanzan(): void
    {
        foreach ([
            'tienda:vendedor',
            'tienda:admin',
            'admin',                          // sin prefijo: el SSO no lo emite nunca
            'treslogadmin',
            'treslog:admins',
            'treslog:customer,tienda:admin',  // dos roles, ninguno sirve
        ] as $forjado) {
            $this->withHeaders($this->delGateway(['X-User-Roles' => $forjado]))
                ->getJson('/api/treslog/roles')
                ->assertStatus(403, "«{$forjado}» alcanzo para administrar TR3SLOG")
                ->assertJsonPath('error', 'forbidden');
        }
    }

    // =======================================================================
    //  4.7 — Agujero 3: invitations/* "without auth for now"
    // =======================================================================

    public function test_invitaciones_pendientes_sin_cabeceras_de_gateway_responde_401(): void
    {
        UserInvitation::create([
            'email'      => 'filtrada@ejemplo.test',
            'name'       => 'Persona Filtrada',
            'token'      => 'tok-filtrada',
            'status'     => 'pending',
            'expires_at' => Carbon::now()->addDays(7),
        ]);

        $r = $this->getJson('/api/treslog/invitations/pending');

        $r->assertStatus(401)->assertJsonPath('error', 'unauthenticated');

        // El status por si solo no alcanza: lo que estaba mal era el CONTENIDO.
        $this->assertStringNotContainsString('filtrada@ejemplo.test', $r->getContent());
        $this->assertStringNotContainsString('Persona Filtrada', $r->getContent());
    }

    /**
     * El 401 tiene que llegar con `request_id`, porque es lo unico con lo que se
     * encuentra esta peticion en los logs del gateway, del SSO y del backend.
     * Un error de auth sin correlacion es un ticket de soporte irresoluble.
     */
    public function test_el_401_del_gateway_llega_con_request_id(): void
    {
        $this->getJson('/api/treslog/invitations/pending')
            ->assertStatus(401)
            ->assertJsonStructure(['error', 'message', 'request_id']);

        $this->withHeader('X-Request-Id', 'e1b2c3d4e5f60718293a4b5c6d7e8f90')
            ->getJson('/api/treslog/invitations/pending')
            ->assertStatus(401)
            ->assertJsonPath('request_id', 'e1b2c3d4e5f60718293a4b5c6d7e8f90');
    }

    public function test_las_cuatro_rutas_administrativas_de_invitaciones_estan_cerradas_por_los_dos_caminos(): void
    {
        foreach ([
            ['post', '/send'],
            ['post', '/bulk'],
            ['get',  '/pending'],
            ['post', '/resend'],
        ] as [$metodo, $cola]) {
            // Camino viejo: sin token -> 401 (antes: 200 / correo enviado).
            $this->{$metodo.'Json'}('/api/invitations'.$cola)
                ->assertStatus(401, "«/api/invitations{$cola}» sigue abierta a internet");

            // Camino nuevo: sin sello del gateway -> 401.
            $this->{$metodo.'Json'}('/api/treslog/invitations'.$cola)
                ->assertStatus(401, "«/api/treslog/invitations{$cola}» sigue abierta a internet");
        }
    }

    public function test_invitaciones_pendientes_con_treslog_admin_si_responde(): void
    {
        UserInvitation::create([
            'email'      => 'esperada@ejemplo.test',
            'name'       => 'Persona Esperada',
            'token'      => 'tok-esperada',
            'status'     => 'pending',
            'expires_at' => Carbon::now()->addDays(7),
        ]);

        $this->withHeaders($this->delGateway())
            ->getJson('/api/treslog/invitations/pending')
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    /**
     * LA DIVERGENCIA, CONGELADA. `verify` y `accept` siguen publicas.
     *
     * La spec dice "todo endpoint de invitations/*". Aplicada a estas dos, la
     * regla se contradice sola: quien las llama es el invitado, que todavia no
     * tiene cuenta — `acceptInvitation` es el codigo que se la crea. Pedirle
     * identidad resuelta es pedirle estar adentro para poder entrar.
     *
     * Si este test se pone rojo porque alguien les puso `gateway.auth`, no se
     * "arregla" el test: se revierte el middleware. La invitacion deja de poder
     * aceptarse y nadie se entera hasta que un cliente lo reporte.
     */
    public function test_verificar_y_aceptar_una_invitacion_siguen_siendo_publicas(): void
    {
        $this->rol('customer');

        UserInvitation::create([
            'email'      => 'invitada@ejemplo.test',
            'name'       => 'Persona Invitada',
            'token'      => 'tok-valido-123',
            'status'     => 'pending',
            'expires_at' => Carbon::now()->addDays(7),
        ]);

        // Sin token de Sanctum y sin cabeceras del gateway: el invitado no tiene
        // ni una cosa ni la otra. La credencial es el token de la invitacion.
        $this->postJson('/api/invitations/verify', ['token' => 'tok-valido-123'])
            ->assertStatus(200);

        $this->postJson('/api/invitations/accept', [
            'token'    => 'tok-valido-123',
            'password' => 'Abcdef1!',
        ])->assertStatus(201);

        $this->assertTrue(
            User::where('email', 'invitada@ejemplo.test')->exists(),
            'Aceptar una invitacion dejo de crear la cuenta: la funcion esta muerta.'
        );
    }

    // =======================================================================
    //  4.4 — N3: `POST /users`
    // =======================================================================

    /**
     * El plan la llamaba "la cuarta via de alta". NO LO ERA: la ruta estaba
     * fuera de `auth:sanctum`, el guard por defecto es `web` (sesion), y
     * `UserController::store` abre con `authorize('create', User::class)`, asi
     * que un invitado —y tambien un admin con Bearer valido, comprobado—
     * recibia 403. Era codigo muerto, no un agujero.
     *
     * Se retira igual, y este test explica por que: mientras la ruta exista,
     * alguien la va a mover adentro de `auth:sanctum` "para que funcione", y ahi
     * si se convierte en la cuarta via de alta que el plan creia cerrar.
     */
    public function test_el_alta_publica_de_usuarios_ya_no_existe(): void
    {
        $this->rol('admin');

        // 405 y no 404: `GET /api/users` sigue existiendo (listado, dentro de
        // `auth:sanctum`), asi que la URI matchea y lo que Laravel rechaza es el
        // METODO. Se afirma el 405 exacto a proposito — un 404 aca significaria
        // que se llevaron puesto tambien el listado, que no es de este lote.
        $this->postJson('/api/users', [
            'name'   => 'Colada',
            'email'  => 'colada@ejemplo.test',
            'roles'  => [1],
            'status' => 'active',
        ])->assertStatus(405);

        // Y el listado, que no se toco, sigue en pie detras de su guarda.
        $this->getJson('/api/users')->assertStatus(401);

        $this->assertFalse(
            User::where('email', 'colada@ejemplo.test')->exists(),
            'El alta publica de usuarios volvio a existir.'
        );
    }

    // =======================================================================
    //  Lo que este lote NO tenia que tocar
    // =======================================================================

    /**
     * La regla dura del lote: solo cambian roles/*, permissions/*, zones/*,
     * invitations/* y el alta publica. Un `customer` con token sigue teniendo el
     * mismo acceso que antes al resto del dominio.
     *
     * Sin esto, "cerrar agujeros" y "romperle el backend a los clientes que
     * todavia no migraron" se parecen demasiado en el diff.
     */
    public function test_el_resto_del_dominio_sigue_igual_para_un_customer_con_token(): void
    {
        $token = $this->conTokenSanctum('customer');

        foreach (['/api/user', '/api/addresses', '/api/quotes', '/api/shipments', '/api/support'] as $ruta) {
            $r = $this->withHeader('Authorization', 'Bearer '.$token)->getJson($ruta);

            $this->assertNotSame(
                401,
                $r->status(),
                "«{$ruta}» empezo a pedir credenciales que antes no pedia: se rompio un cliente sin migrar"
            );
        }
    }
}
