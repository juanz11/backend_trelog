<?php

namespace Tests\Feature\Sso;

use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `sso:espejo`: la fila local que hace que una persona del SSO exista en
 * TR3SLOG. Es lo que separa «entra» de «tu cuenta no esta habilitada».
 */
class EspejoCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_crea_el_espejo_con_el_sso_user_id_dado(): void
    {
        $this->artisan('sso:espejo', ['email' => 'ana@tr3slog.test', 'sso_user_id' => '3'])
            ->expectsOutputToContain('CREADO ana@tr3slog.test')
            ->assertSuccessful();

        $u = User::where('email', 'ana@tr3slog.test')->firstOrFail();
        $this->assertSame('3', (string) $u->sso_user_id);
        $this->assertNull($u->driverProfile);
    }

    public function test_es_idempotente_y_corrige_un_id_cruzado(): void
    {
        // El caso real: alguien creo el espejo con el id del SSO LOCAL (5) y
        // ahora trabaja contra el VPS (3). Volver a correrlo lo alinea, no
        // duplica la persona.
        $this->artisan('sso:espejo', ['email' => 'ana@tr3slog.test', 'sso_user_id' => '5'])->assertSuccessful();
        $this->artisan('sso:espejo', ['email' => 'ana@tr3slog.test', 'sso_user_id' => '3'])
            ->expectsOutputToContain('(corregido)')
            ->assertSuccessful();

        $this->assertSame(1, User::where('email', 'ana@tr3slog.test')->count());
        $this->assertSame('3', (string) User::where('email', 'ana@tr3slog.test')->value('sso_user_id'));
    }

    public function test_no_pisa_el_id_de_otra_persona(): void
    {
        // Dos correos con el mismo sso_user_id es un id mal copiado, nunca un
        // caso valido. Se corta con el nombre del dueño, no con un 500 de UNIQUE.
        $this->artisan('sso:espejo', ['email' => 'ana@tr3slog.test', 'sso_user_id' => '3'])->assertSuccessful();

        $this->artisan('sso:espejo', ['email' => 'otro@tr3slog.test', 'sso_user_id' => '3'])
            ->expectsOutputToContain('ya es de ana@tr3slog.test')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'otro@tr3slog.test']);
    }

    public function test_con_conductor_crea_el_perfil_una_sola_vez(): void
    {
        $this->artisan('sso:espejo', ['email' => 'ana@tr3slog.test', 'sso_user_id' => '3', '--conductor' => true])->assertSuccessful();
        $this->artisan('sso:espejo', ['email' => 'ana@tr3slog.test', 'sso_user_id' => '3', '--conductor' => true])
            ->expectsOutputToContain('ya existia')
            ->assertSuccessful();

        $u = User::where('email', 'ana@tr3slog.test')->firstOrFail();
        $this->assertSame(1, DriverProfile::where('user_id', $u->id)->count());
    }

    public function test_el_espejo_entra_por_el_gateway(): void
    {
        // Lo que el comando promete: con el espejo, la identidad del SSO resuelve
        // a esta fila y `/api/treslog/me` responde con ella.
        $this->artisan('sso:espejo', ['email' => 'ana@tr3slog.test', 'sso_user_id' => '3'])->assertSuccessful();

        $this->withHeaders([
            'X-Auth-Gateway'  => 'myglobalhub-gateway',
            'X-User-Id'       => '3',
            'X-User-Clerk-Id' => 'user_clerk_3',
            'X-User-Email'    => 'ana@tr3slog.test',
            'X-User-Name'     => 'Ana',
            'X-User-Roles'    => 'treslog:customer',
        ])->getJson('/api/treslog/me')
            ->assertOk()
            ->assertJsonPath('data.sso_user_id', '3')
            ->assertJsonPath('data.email', 'ana@tr3slog.test');
    }
}
