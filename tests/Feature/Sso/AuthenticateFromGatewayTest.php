<?php

namespace Tests\Feature\Sso;

use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Tabla de verdad COMPLETA de `gateway.auth` (4-tasks.md 3.8).
 *
 * No es cobertura: cada caso de aca es un escenario textual de la spec, y los
 * cinco negativos son los cinco modos de falla que separan "el gateway esta mal
 * configurado" de "alguien esta pegando directo al backend". Si el middleware
 * confunde dos de esos casos, el sintoma en produccion es una tarde de alguien
 * revisando NGINX por un problema que estaba en otro lado.
 *
 * Ojo con el caso de X-User-Name y X-User-Roles: NGINX OMITE un
 * proxy_set_header de valor vacio, asi que la cabecera ausente NO es un error
 * del cliente — es como llega una persona sin nombre o sin roles. Tratarla como
 * error deja a esa gente afuera del sistema sin ninguna pista.
 */
class AuthenticateFromGatewayTest extends TestCase
{
    use MontaRutasDePrueba;

    private const URI = '/api/treslog/_prueba/identidad';

    protected function setUp(): void
    {
        parent::setUp();

        $this->montarRuta(self::URI, ['gateway.auth']);
    }

    // -----------------------------------------------------------------------
    //  Los cinco negativos de la spec
    // -----------------------------------------------------------------------

    public function test_sin_cabecera_de_sello_responde_401_unauthenticated(): void
    {
        $this->getJson(self::URI)
            ->assertStatus(401)
            ->assertJsonPath('error', 'unauthenticated');
    }

    public function test_sello_forjado_con_otro_valor_responde_401_igual_que_si_faltara(): void
    {
        $this->getJson(self::URI, ['X-Auth-Gateway' => 'cualquier-otra-cosa'])
            ->assertStatus(401)
            ->assertJsonPath('error', 'unauthenticated');
    }

    public function test_el_401_lleva_request_id_en_el_cuerpo_no_el_slug_unauthorized_de_msh(): void
    {
        // DIVERGENCIA 1 respecto de MSH, congelada. `unauthorized` no esta en el
        // catalogo cerrado de trece del contrato; el gateway, para esta misma
        // condicion, emite `unauthenticated`. Con dos vocabularios el cliente no
        // reconoce su propio 401 y no vuelve al login.
        $cuerpo = $this->getJson(self::URI)->assertStatus(401)->json();

        $this->assertSame('unauthenticated', $cuerpo['error']);
        $this->assertArrayHasKey('request_id', $cuerpo);
        $this->assertNotEmpty($cuerpo['request_id'], 'Un error de auth sin request_id es un ticket de soporte irresoluble.');
    }

    public function test_con_sello_pero_sin_identidad_responde_401_y_no_500(): void
    {
        // Gateway mal configurado. Es 401 y no 500 a proposito: un 500 haria que
        // el cliente reintente contra algo que nunca va a funcionar, y ensuciaria
        // la alerta de errores de servidor con un problema de configuracion.
        $this->getJson(self::URI, ['X-Auth-Gateway' => 'myglobalhub-gateway'])
            ->assertStatus(401)
            ->assertJsonPath('error', 'unauthenticated');
    }

    public function test_sin_x_user_name_resuelve_200_porque_nginx_omite_las_cabeceras_vacias(): void
    {
        $cabeceras = $this->cabecerasDelGateway();
        unset($cabeceras['X-User-Name']);

        $this->getJson(self::URI, $cabeceras)
            ->assertStatus(200)
            ->assertJsonPath('sso_user.name', null)
            ->assertJsonPath('sso_user.id', '4242');
    }

    public function test_sin_x_user_roles_la_lista_queda_vacia_sin_excepcion(): void
    {
        $cabeceras = $this->cabecerasDelGateway();
        unset($cabeceras['X-User-Roles']);

        $this->getJson(self::URI, $cabeceras)
            ->assertStatus(200)
            ->assertJsonPath('sso_user.roles', []);
    }

    // -----------------------------------------------------------------------
    //  El camino feliz y el contrato de X-User-Roles
    // -----------------------------------------------------------------------

    public function test_con_todas_las_cabeceras_publica_la_identidad_completa_en_el_request(): void
    {
        $this->getJson(self::URI, $this->cabecerasDelGateway())
            ->assertStatus(200)
            ->assertJson(['sso_user' => [
                'id'       => '4242',
                'clerk_id' => 'user_2abcClerk',
                'email'    => 'conductor@tr3slog.test',
                'name'     => 'Ana Conductora',
                'roles'    => ['treslog:driver'],
            ]]);
    }

    public function test_los_roles_se_parten_por_coma_y_se_descartan_los_vacios(): void
    {
        // Un rol "" concedido por una coma de mas seria un rol que nadie otorgo.
        $this->getJson(self::URI, $this->cabecerasDelGateway([
            'X-User-Roles' => ' treslog:driver , ,treslog:customer,',
        ]))
            ->assertStatus(200)
            ->assertJsonPath('sso_user.roles', ['treslog:driver', 'treslog:customer']);
    }

    public function test_la_identidad_no_se_publica_como_singleton_del_contenedor(): void
    {
        // Bajo Octane/Swoole el contenedor sobrevive entre peticiones: un binding
        // 'sso.user' filtraria la identidad de un usuario a la peticion del
        // siguiente. Vive SOLO en el request.
        $this->getJson(self::URI, $this->cabecerasDelGateway())->assertStatus(200);

        $this->assertFalse(app()->bound('sso.user'));
    }

    // -----------------------------------------------------------------------
    //  Las dos divergencias respecto de MSH, congeladas
    // -----------------------------------------------------------------------

    public function test_el_sello_se_evalua_antes_que_la_identidad(): void
    {
        // DIVERGENCIA 2 (a). Una peticion directa al backend —sin sello y sin
        // identidad, que es como llega un curl— tiene que loguearse como "esto
        // no vino del gateway", NO como "el gateway no inyecto la identidad".
        // Son diagnosticos opuestos: el primero manda a revisar la red, el
        // segundo manda a revisar el NGINX. Con el orden de MSH (identidad
        // primero) este caso reporta el segundo, y alguien se pasa la tarde
        // mirando un template que estaba bien.
        //
        // OJO, y corrige a 3-design.md §C.2: el ejemplo del diseño es una
        // peticion "con un X-User-Id forjado y sin sello", y ESE caso NO
        // distingue los dos ordenes (con la identidad presente, MSH tambien
        // llega a la comprobacion de firma y acierta el motivo). El caso que
        // realmente los separa es este: sin ninguna de las dos cabeceras.
        Log::spy();

        $this->getJson(self::URI)->assertStatus(401);

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $mensaje, array $contexto) => $contexto['reason'] === 'gateway_signature_mismatch'
        )->once();
    }

    public function test_vaciar_la_firma_en_config_no_desactiva_la_comprobacion(): void
    {
        // DIVERGENCIA 2 (b). En MSH la firma se comprueba solo `if (filled(...))`
        // sobre un valor de env(), o sea que un `.env` con la variable vacia
        // apaga un MUST de la spec: el test pasa en CI y miente en el ambiente
        // donde quedo vacia. Aca falla CERRADO.
        config(['sso.gateway_signature' => '']);

        $this->getJson(self::URI, $this->cabecerasDelGateway(['X-Auth-Gateway' => '']))
            ->assertStatus(401);

        $this->getJson(self::URI, $this->cabecerasDelGateway())
            ->assertStatus(401);
    }

    public function test_el_log_del_rechazo_registra_presencia_de_cabeceras_nunca_su_contenido(): void
    {
        // Un log de diagnostico que copia credenciales las convierte en un
        // archivo de texto plano en un volumen compartido: una filtracion con
        // otro nombre.
        Log::spy();

        $this->getJson(self::URI, [
            'Authorization'  => 'Bearer un-token-que-no-debe-aparecer-jamas',
            'X-Auth-Gateway' => 'sello-forjado-que-tampoco',
        ])->assertStatus(401);

        Log::shouldHaveReceived('warning')->withArgs(function (string $mensaje, array $contexto) {
            $serializado = json_encode($contexto);

            $this->assertTrue($contexto['has_authorization_header']);
            $this->assertStringNotContainsString('un-token-que-no-debe-aparecer-jamas', $serializado);
            $this->assertStringNotContainsString('sello-forjado-que-tampoco', $serializado);

            return true;
        })->once();
    }
}
