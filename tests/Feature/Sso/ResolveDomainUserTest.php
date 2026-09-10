<?php

namespace Tests\Feature\Sso;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La costura de identidad: del `X-User-Id` del SSO a la fila de `users` de la
 * que cuelgan las nueve foreign keys del dominio (4-tasks.md 3.10).
 *
 * ES LA PIEZA MAS DELICADA DE TODO EL CAMBIO. Equivocarse aca no da un error:
 * da la cuenta de otra persona. Por eso el test que manda en este archivo es el
 * anti-secuestro, y esta escrito para que rompa ruidosamente el dia que alguien
 * "mejore" la resolucion agregando un `firstOrCreate` por email.
 */
class ResolveDomainUserTest extends TestCase
{
    use MontaRutasDePrueba;
    use RefreshDatabase;

    private const URI = '/api/treslog/_prueba/dominio';

    protected function setUp(): void
    {
        parent::setUp();

        $this->montarRuta(self::URI, ['gateway.auth', 'gateway.user']);
    }

    /**
     * `sso_user_id` esta FUERA de $fillable a proposito, asi que el vinculo se
     * escribe con forceFill. La friccion es el punto: el ancla de identidad no
     * puede entrar por un `User::create($request->all())`.
     */
    private function usuarioVinculado(string $ssoUserId, array $atributos = []): User
    {
        $user = User::factory()->create($atributos);

        $user->forceFill(['sso_user_id' => $ssoUserId])->save();

        return $user->fresh();
    }

    // -----------------------------------------------------------------------
    //  LA INVARIANTE: nunca, jamas, un vinculo automatico por email
    // -----------------------------------------------------------------------

    public function test_email_coincidente_sin_sso_user_id_responde_403_y_NO_vincula_la_cuenta(): void
    {
        // El email del SSO lo controla la persona desde su perfil de Clerk. Si
        // esta resolucion vinculara por correo, cualquiera que registre en el SSO
        // el email de un cliente de TR3SLOG se queda con SU cuenta de logistica:
        // sus envios, sus direcciones, su facturacion. No es un caso borde: es la
        // forma normal de robar una cuenta cuando el proveedor de identidad y el
        // dominio no comparten el registro.
        $victima = User::factory()->create([
            'email' => 'victima@tr3slog.test',
            'name'  => 'Cliente De Toda La Vida',
        ]);

        $this->getJson(self::URI, $this->cabecerasDelGateway([
            'X-User-Id'    => '666',
            'X-User-Email' => 'victima@tr3slog.test',
            'X-User-Name'  => 'Impostor Con El Mismo Mail',
        ]))
            ->assertStatus(403)
            ->assertJsonPath('error', 'forbidden');

        $victima->refresh();

        $this->assertNull($victima->sso_user_id, 'SE VINCULO LA CUENTA POR EMAIL: eso es un secuestro de cuenta.');
        $this->assertSame('Cliente De Toda La Vida', $victima->name, 'Se piso el nombre de una cuenta que no era.');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_identidad_del_sso_sin_fila_local_responde_403_explicito_y_no_crea_nada(): void
    {
        // 403 y no 401: un 401 dice "volve a loguearte", y loguearse de nuevo no
        // lo arregla nunca. Son dos acciones distintas del lado del cliente.
        $this->getJson(self::URI, $this->cabecerasDelGateway(['X-User-Id' => '777']))
            ->assertStatus(403)
            ->assertJsonPath('error', 'forbidden')
            ->assertJsonPath('request_id', fn ($v) => is_string($v) && $v !== '');

        $this->assertDatabaseCount('users', 0);
    }

    // -----------------------------------------------------------------------
    //  El camino feliz
    // -----------------------------------------------------------------------

    public function test_el_mismo_sso_user_id_resuelve_la_misma_fila_en_dos_peticiones(): void
    {
        $user = $this->usuarioVinculado('4242');

        $primera = $this->getJson(self::URI, $this->cabecerasDelGateway())->assertStatus(200);
        $segunda = $this->getJson(self::URI, $this->cabecerasDelGateway())->assertStatus(200);

        $this->assertSame($user->id, $primera->json('user_id'));
        $this->assertSame($user->id, $segunda->json('user_id'));
        $this->assertDatabaseCount('users', 1);
    }

    public function test_deja_el_usuario_de_dominio_como_autenticado_del_request(): void
    {
        // Es lo unico que hace que `$request->user()->id`, las cuatro Policies y
        // los nueve controladores sigan funcionando sin tocarse. Si esto se
        // rompe, la comparacion `$route->driver_id === $request->user()->id`
        // deja de tener con que comparar y el conductor pierde sus rutas.
        $user = $this->usuarioVinculado('4242');

        $this->getJson(self::URI, $this->cabecerasDelGateway())
            ->assertStatus(200)
            ->assertJsonPath('user_id', $user->id);
    }

    public function test_refresca_las_columnas_que_gobierna_el_sso(): void
    {
        // `users.name` y `users.email` sobreviven como CACHE DE LECTURA. El
        // riesgo real de dejar la tabla espejo es que alguien lea `users.name`
        // creyendo que es autoritativo; por eso se refresca en cada peticion.
        $user = $this->usuarioVinculado('4242', [
            'email' => 'viejo@tr3slog.test',
            'name'  => 'Nombre Viejo',
        ]);

        $this->getJson(self::URI, $this->cabecerasDelGateway([
            'X-User-Email'    => 'nuevo@tr3slog.test',
            'X-User-Name'     => 'Nombre Nuevo',
            'X-User-Clerk-Id' => 'user_2nuevoClerk',
        ]))->assertStatus(200);

        $user->refresh();

        $this->assertSame('nuevo@tr3slog.test', $user->email);
        $this->assertSame('Nombre Nuevo', $user->name);
        $this->assertSame('user_2nuevoClerk', $user->sso_clerk_id);
        // El ancla NO se toca nunca despues de resolverla.
        $this->assertSame('4242', $user->sso_user_id);
    }

    public function test_una_cabecera_ausente_no_borra_el_dato_local(): void
    {
        // Una cabecera de valor vacio NO LLEGA (NGINX omite el proxy_set_header).
        // Pisar el nombre local con "" seria interpretar "no me mandaron el dato"
        // como "el dato es vacio", que son cosas distintas.
        $user = $this->usuarioVinculado('4242', ['name' => 'Nombre Que Ya Teniamos']);

        $cabeceras = $this->cabecerasDelGateway();
        unset($cabeceras['X-User-Name']);

        $this->getJson(self::URI, $cabeceras)->assertStatus(200);

        $this->assertSame('Nombre Que Ya Teniamos', $user->refresh()->name);
    }

    public function test_un_choque_de_email_unico_no_tira_500_y_la_peticion_sigue_resolviendo(): void
    {
        // `users.email` es UNIQUE. Si el SSO manda un email que otra fila local ya
        // ocupa, el UPDATE explota — y eso NO puede convertirse en un 500 en cada
        // peticion de esa persona: la identidad ya esta resuelta por sso_user_id,
        // lo que fallo es refrescar un cache. Fallar la peticion no arregla el
        // choque, solo deja al usuario afuera.
        $ocupante = User::factory()->create(['email' => 'ocupado@tr3slog.test']);
        $user     = $this->usuarioVinculado('4242', ['email' => 'propio@tr3slog.test']);

        $this->getJson(self::URI, $this->cabecerasDelGateway([
            'X-User-Email' => $ocupante->email,
        ]))
            ->assertStatus(200)
            ->assertJsonPath('user_id', $user->id);

        $this->assertSame('propio@tr3slog.test', $user->refresh()->email);
    }

    // -----------------------------------------------------------------------
    //  Cadena mal armada y superficie de asignacion masiva
    // -----------------------------------------------------------------------

    public function test_gateway_user_sin_gateway_auth_delante_responde_401_y_no_resuelve_anonimo(): void
    {
        // Falla ruidosa a proposito: seguir de largo sin usuario dejaria una ruta
        // "protegida" resolviendo anonima, que es el peor final posible.
        $this->montarRuta('/api/treslog/_prueba/cadena-rota', ['gateway.user']);

        $this->getJson('/api/treslog/_prueba/cadena-rota', $this->cabecerasDelGateway())
            ->assertStatus(401)
            ->assertJsonPath('error', 'unauthenticated');
    }

    public function test_el_ancla_de_identidad_no_es_asignable_en_masa(): void
    {
        // Un `sso_user_id` en $fillable es un secuestro de cuenta esperando un
        // `User::create($request->all())`: quien controle el cuerpo de una
        // peticion elige de quien es la fila.
        $user = new User;
        $user->fill(['sso_user_id' => 'me-lo-invente', 'sso_clerk_id' => 'tambien']);

        $this->assertNull($user->sso_user_id);
        $this->assertNull($user->sso_clerk_id);
    }
}
