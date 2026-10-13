<?php

namespace Tests\Feature\Sso;

use Tests\TestCase;

/**
 * ############################################################################
 * #  HALLAZGO ABIERTO DE LA AUDITORIA — «rompe-produccion» n.o 1             #
 * #  Esto NO es un test de una funcionalidad. Es un agujero, escrito como    #
 * #  test para que viva en la suite y no en un documento que nadie abre.     #
 * ############################################################################
 *
 * EL AGUJERO
 * ----------
 * El unico control de seguridad REAL de este diseño es el aislamiento de red:
 * el backend no debe ser alcanzable mas que desde el contenedor del gateway
 * (spec: «Backend inalcanzable fuera del gateway»; config/sso.php lo dice con
 * todas las letras: «esto NO es un secreto y no autentica nada»).
 *
 * Ese aislamiento ES IMPOSIBLE DURANTE LA TRANSICION. Las rutas viejas —el
 * bloque `auth:sanctum`, la web Next, la app de conductores instalada en la
 * calle— no tienen `location` en el gateway, asi que el backend TIENE que
 * seguir publicado para que sigan funcionando. Y publicado, `X-Auth-Gateway`
 * es una constante impresa en un repositorio: cualquiera que alcance el puerto
 * la copia, se la manda, y entra como quien quiera, con los roles que quiera.
 *
 * Los dos tests de abajo lo DEMUESTRAN con el valor que el sistema produce hoy.
 * No hay ningun assert que diga «esto esta bien»: dicen «esto es lo que pasa».
 *
 * QUE NO ES ESTO
 * --------------
 * No es un bug de `AuthenticateFromGateway`: el middleware hace exactamente lo
 * que puede hacer. Ningun codigo dentro del backend puede cerrar esto, porque
 * la unica evidencia que recibe es una cabecera de texto plano.
 *
 * LA MITIGACION REAL — DECISION PENDIENTE, CON DUEÑO
 * --------------------------------------------------
 * Cualquiera de estas tres cierra el agujero, y las tres son de infraestructura:
 *
 *   1. Allowlist de IP en el backend (solo la IP del contenedor del gateway).
 *      La mas barata; se cae si el gateway cambia de red o se escala.
 *   2. mTLS entre gateway y backend. La correcta; cuesta emitir y rotar certs.
 *   3. Un secreto compartido ROTADO (no una constante en el repo), inyectado
 *      por ambiente. Intermedia; hay que resolver donde vive y como se rota.
 *
 * Ninguna se decide en este lote y ninguna se puede decidir desde el codigo:
 * dependen de como se despliega. Mientras tanto, ESTA ES LA SUPERFICIE ABIERTA,
 * y estos tests la mantienen a la vista.
 *
 * CUANDO ESTOS TESTS SE PONGAN EN ROJO, LEE ESTE COMENTARIO ANTES DE
 * ARREGLARLOS: si empezaron a fallar, es que alguien cerro el agujero (buena
 * noticia) o que roto el sello (mejor todavia). En los dos casos lo que hay que
 * actualizar es este archivo, a proposito y con una linea diciendo cual fue.
 */
class SuperficieAbiertaSelloForjableTest extends TestCase
{
    use MontaRutasDePrueba;

    /**
     * El valor se escribe LITERAL a proposito, no leido de config('sso...').
     *
     * Leerlo de la config haria que el test se auto-adapte a una rotacion y el
     * hallazgo se volveria invisible justo el dia que se arregla. Escrito a
     * mano, cualquier cambio del sello rompe este archivo y obliga a leer el
     * comentario de arriba.
     */
    private const SELLO_IMPRESO_EN_EL_REPO = 'myglobalhub-gateway';

    public function test_el_sello_es_una_constante_publica_del_repositorio_no_un_secreto(): void
    {
        $this->assertSame(
            self::SELLO_IMPRESO_EN_EL_REPO,
            config('sso.gateway_signature'),
            'Cambio el sello del gateway: revisa el comentario de cabecera de este archivo.'
        );
    }

    public function test_una_peticion_directa_con_el_sello_forjado_entra_como_cualquier_usuario(): void
    {
        $this->montarRuta('/api/treslog/_prueba/superficie', ['gateway.auth']);

        // Ni token, ni sesion, ni nada que el atacante no pueda escribir a mano.
        $this->getJson('/api/treslog/_prueba/superficie', [
            'X-Auth-Gateway' => self::SELLO_IMPRESO_EN_EL_REPO,
            'X-User-Id'      => '1',
            'X-User-Email'   => 'quien-yo-quiera@ejemplo.test',
        ])
            ->assertStatus(200)
            ->assertJsonPath('sso_user.id', '1');
    }

    public function test_y_ademas_se_puede_forjar_el_rol_de_administrador(): void
    {
        // No hace falta ni siquiera adivinar un id valido: `sso.role` autoriza
        // mirando la misma cabecera de texto plano que el atacante escribio.
        $this->montarRuta('/api/treslog/_prueba/superficie-admin', [
            'gateway.auth',
            'sso.role:treslog:admin',
        ]);

        $this->getJson('/api/treslog/_prueba/superficie-admin', [
            'X-Auth-Gateway' => self::SELLO_IMPRESO_EN_EL_REPO,
            'X-User-Id'      => '1',
            'X-User-Roles'   => 'treslog:admin',
        ])->assertStatus(200);
    }
}
