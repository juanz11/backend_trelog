<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * `POST /api/forgot-password` no entrega el token de reseteo, pase lo que pase.
 *
 * Existe por un agujero real: cuando fallaba el envio del correo, el endpoint
 * devolvia `reset_link` —con el token adentro— en el cuerpo de la respuesta, con el
 * comentario "Include link for development/testing". Y `POST /api/reset-password`
 * solo exige el token, NO el email. Las dos rutas son publicas.
 *
 * O sea: cualquiera pedia el reseteo de admin@..., recibia el enlace, y cambiaba la
 * contrasena. Toma de cuenta completa, desde internet, sin credenciales.
 *
 * Lo peor es como se llegaba: no habia que romper nada. Alcanzaba con que el SMTP
 * fallara, que es un evento operativo normal —una app password de Gmail vencida, un
 * limite de envio, un corte de red—. El camino de excepcion, el que menos se prueba,
 * era el camino del ataque.
 *
 * Estos tests fallan contra el codigo anterior. Es la unica forma de saber que
 * prueban algo.
 */
class PasswordResetLeakTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(string $email = 'victima@ejemplo.test'): User
    {
        return User::factory()->create(['email' => $email]);
    }

    public function test_con_el_correo_caido_la_respuesta_NO_trae_el_enlace(): void
    {
        $this->usuario();

        // Se fuerza el fallo del envio: es el camino donde vivia el agujero.
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP caido'));

        $r = $this->postJson('/api/forgot-password', ['email' => 'victima@ejemplo.test']);

        $r->assertOk();
        $r->assertJsonMissingPath('reset_link');

        // Se comprueba el cuerpo CRUDO ademas de la clave: si alguien mete el enlace
        // con otro nombre —`link`, `url`, dentro de `data`—, el assert por clave pasa
        // y el agujero vuelve igual.
        $this->assertStringNotContainsString('token=', $r->getContent());
        $this->assertStringNotContainsString('/reset?', $r->getContent());
    }

    public function test_el_token_huerfano_se_revoca(): void
    {
        $u = $this->usuario();

        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP caido'));

        $this->postJson('/api/forgot-password', ['email' => $u->email])->assertOk();

        // Si el correo no salio, ese token no lo tiene nadie. Dejarlo vivo una hora es
        // una credencial valida flotando en la base sin dueno: cualquier cosa que
        // despues filtre la fila entrega la cuenta.
        $u->refresh();
        $this->assertNull($u->reset_token);
        $this->assertNull($u->reset_token_expires);
    }

    public function test_la_respuesta_es_IDENTICA_falle_o_no_el_correo(): void
    {
        $u = $this->usuario();

        Mail::fake();
        $conCorreo = $this->postJson('/api/forgot-password', ['email' => $u->email]);

        // Una respuesta distinta cuando falla el correo le dice a quien pregunta que
        // el correo no salio. Es informacion que no le debemos, y ademas convierte
        // este endpoint en un detector de "esta caido el SMTP", que es justo cuando
        // el sistema esta mas debil.
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP caido'));
        $sinCorreo = $this->postJson('/api/forgot-password', ['email' => $u->email]);

        $this->assertSame($conCorreo->getStatusCode(), $sinCorreo->getStatusCode());
        $this->assertSame($conCorreo->json(), $sinCorreo->json());
    }

    public function test_un_email_inexistente_no_confirma_si_existe_o_no(): void
    {
        $u = $this->usuario();
        Mail::fake();

        $existe = $this->postJson('/api/forgot-password', ['email' => $u->email]);
        $noExiste = $this->postJson('/api/forgot-password', ['email' => 'nadie@ejemplo.test']);

        // ESTE TEST DOCUMENTA UN AGUJERO QUE SIGUE ABIERTO, no una correccion.
        //
        // Hoy el endpoint responde 404 «Email not found» para un correo que no existe:
        // es un oraculo de enumeracion, sin freno, que permite barrer que direcciones
        // tienen cuenta. Cerrarlo cambia el contrato de la web (que muestra ese error)
        // y por eso NO se arregla en esta rama, que es solo la fuga del token.
        //
        // Se deja escrito con su valor REAL para que el dia que se arregle este test
        // falle y obligue a actualizarlo a proposito, en vez de que el agujero
        // sobreviva porque nadie lo tenia anotado.
        $this->assertSame(200, $existe->getStatusCode());
        $this->assertSame(404, $noExiste->getStatusCode(), 'Si esto cambio a 200, el oraculo se cerro: actualiza el test y borra esta nota.');
    }
}
