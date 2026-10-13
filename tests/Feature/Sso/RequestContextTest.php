<?php

namespace Tests\Feature\Sso;

use App\Http\Middleware\RequestContext;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Correlacion por request_id (4-tasks.md 3.9).
 *
 * El request_id es lo unico que permite seguir una peticion por los tres saltos
 * —cliente, gateway, SSO, backend— cuando alguien reporta un error. Lo genera
 * NGINX porque es el unico punto por el que pasa TODO el trafico; este backend
 * no lo inventa, lo propaga. Si el valor entrante se perdiera, el caso "el
 * conductor dice que la app le devuelve datos raros" deja de ser investigable:
 * el id aparece en el log del gateway y del SSO, y se corta justo en el ultimo
 * salto.
 */
class RequestContextTest extends TestCase
{
    use MontaRutasDePrueba;

    /** Con RequestContext montado delante, que es como va a quedar en el Lote 5. */
    private const URI_CADENA = '/api/treslog/_prueba/contexto';

    /** Sin RequestContext: el 401 igual tiene que llevar un id. */
    private const URI_SUELTA = '/api/treslog/_prueba/contexto-suelto';

    protected function setUp(): void
    {
        parent::setUp();

        $this->montarRuta(self::URI_CADENA, [RequestContext::class, 'gateway.auth']);
        $this->montarRuta(self::URI_SUELTA, ['gateway.auth']);
    }

    public function test_el_request_id_entrante_se_propaga_al_cuerpo_del_error(): void
    {
        $this->getJson(self::URI_CADENA, ['X-Request-Id' => 'sso-abc123'])
            ->assertStatus(401)
            ->assertJsonPath('request_id', 'sso-abc123');
    }

    public function test_sin_request_id_entrante_se_genera_uno_nunca_vacio(): void
    {
        $id = $this->getJson(self::URI_CADENA)->assertStatus(401)->json('request_id');

        $this->assertNotEmpty($id);
        $this->assertNotNull($id);
    }

    public function test_el_id_generado_lleva_el_prefijo_de_esta_aplicacion(): void
    {
        // `treslog-` y no `msh-`: el prefijo existe para saber QUIEN genero el id
        // cuando el log esta agregado con el de las otras aplicaciones. Copiar el
        // de MSH seria mentir con confianza.
        $id = $this->getJson(self::URI_CADENA)->assertStatus(401)->json('request_id');

        $this->assertStringStartsWith('treslog-', $id);
    }

    public function test_un_request_id_con_forma_invalida_se_descarta_y_no_llega_al_cuerpo(): void
    {
        // El valor lo controla quien pega directo al backend. Sin este filtro,
        // un tercero podria empujar kilobytes de basura por linea de log —llenar
        // el disco del VPS— o meter caracteres que rompan el parseo aguas abajo.
        $basura = str_repeat('a', 200);

        $id = $this->getJson(self::URI_CADENA, ['X-Request-Id' => $basura])
            ->assertStatus(401)
            ->json('request_id');

        $this->assertNotSame($basura, $id);
        $this->assertStringStartsWith('treslog-', $id);
    }

    public function test_el_id_queda_disponible_para_el_resto_de_la_peticion(): void
    {
        $this->montarRuta(
            '/api/treslog/_prueba/contexto-ok',
            [RequestContext::class, 'gateway.auth'],
            fn (Request $request) => response()->json([
                'atributo' => $request->attributes->get(RequestContext::ATTRIBUTE),
                // La cabecera entrante se normaliza para que cualquier codigo que
                // la lea mas adelante vea SIEMPRE un id valido.
                'cabecera' => $request->header('X-Request-Id'),
            ]),
        );

        $this->getJson('/api/treslog/_prueba/contexto-ok', $this->cabecerasDelGateway())
            ->assertStatus(200)
            ->assertJsonPath('atributo', fn ($v) => is_string($v) && $v !== '')
            ->assertJsonPath('cabecera', fn ($v) => is_string($v) && $v !== '');
    }

    public function test_sin_request_context_montado_el_401_igual_trae_request_id(): void
    {
        // En el Lote 3 RequestContext NO esta en el grupo `api` todavia (el
        // codigo es inerte a proposito), asi que `gateway.auth` tiene que poder
        // resolver el id por su cuenta. Un 401 con "request_id": null cumpliria
        // la letra del JSON y romperia lo unico para lo que sirve el campo.
        $this->getJson(self::URI_SUELTA, ['X-Request-Id' => 'sso-solo-gateway'])
            ->assertStatus(401)
            ->assertJsonPath('request_id', 'sso-solo-gateway');

        $suelto = $this->getJson(self::URI_SUELTA)->assertStatus(401)->json('request_id');

        $this->assertNotEmpty($suelto);
    }
}
