<?php

namespace Tests\Feature\Sso;

use App\Models\DriverProfile;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as PeticionHttp;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Alta de conductor desde la consola, por el gateway: operaciones da un correo,
 * el SSO promueve a la persona a `treslog:driver`, y aca queda el espejo y el
 * DriverProfile. Sin contraseña. El SSO se simula con Http::fake.
 */
class AltaDeConductorPorElSsoTest extends TestCase
{
    use RefreshDatabase;

    private const SSO = 'https://sso.test';

    private User $operaciones;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sso.base_url' => self::SSO, 'sso.backend_client.id' => 'cli', 'sso.backend_client.secret' => 'sec']);
        Cache::flush();
        Role::firstOrCreate(['name' => 'driver'], ['display_name' => 'Driver']);
        Role::firstOrCreate(['name' => 'operations'], ['display_name' => 'Operations']);
        $this->operaciones = User::factory()->create(['email' => 'ops@tr3slog.test']);
        $this->operaciones->forceFill(['sso_user_id' => '10'])->save();
    }

    private function comoOperaciones(): static
    {
        return $this->withHeaders([
            'X-Auth-Gateway' => 'myglobalhub-gateway', 'X-User-Id' => '10', 'X-User-Clerk-Id' => 'user_clerk_10',
            'X-User-Email' => 'ops@tr3slog.test', 'X-User-Name' => 'Ops', 'X-User-Roles' => 'treslog:operations',
        ]);
    }

    private function ssoConPersona(array $persona = ['id' => '55', 'email' => 'ana@ejemplo.com', 'name' => 'Ana Perez', 'is_active' => true, 'roles' => ['treslog:customer']]): void
    {
        Http::fake([
            self::SSO.'/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            self::SSO.'/api/v1/apps/users?email=*' => Http::response(['data' => $persona]),
            self::SSO.'/api/v1/apps/users/55/roles' => Http::response(['data' => $persona + ['roles' => ['treslog:customer', 'treslog:driver']]]),
        ]);
    }

    public function test_promueve_a_una_persona_del_sso_y_deja_espejo_y_perfil_sin_contraseña(): void
    {
        $this->ssoConPersona();

        $r = $this->comoOperaciones()->postJson('/api/treslog/drivers', [
            'email' => 'Ana@Ejemplo.com', 'vehicle' => 'Van 12', 'hub' => 'Norte', 'phone' => '+58 424',
        ]);

        $r->assertStatus(201)->assertJsonPath('email', 'ana@ejemplo.com')->assertJsonPath('name', 'Ana Perez')->assertJsonPath('v', 'Van 12');

        Http::assertSent(fn (PeticionHttp $p) => $p->url() === self::SSO.'/oauth/token' && $p['scope'] === 'app.users');
        Http::assertSent(fn (PeticionHttp $p) => str_starts_with($p->url(), self::SSO.'/api/v1/apps/users?email=') && $p->hasHeader('Authorization', 'Bearer tok'));
        Http::assertSent(fn (PeticionHttp $p) => $p->url() === self::SSO.'/api/v1/apps/users/55/roles' && $p['role'] === 'treslog:driver');

        $espejo = User::where('sso_user_id', '55')->first();
        $this->assertNotNull($espejo);
        $this->assertSame('ana@ejemplo.com', $espejo->email);
        $this->assertSame('+58 424', $espejo->phone);
        // `password` es NOT NULL hasta el Lote 9: la fila lleva una que nadie conoce.
        $this->assertFalse(\Illuminate\Support\Facades\Hash::check('', (string) $espejo->password));
        $this->assertTrue($espejo->roles()->where('name', 'driver')->exists());
        $perfil = DriverProfile::where('user_id', $espejo->id)->first();
        $this->assertSame('Van 12', $perfil->vehicle);
        $this->assertSame('AP', $perfil->initials);
        $this->assertStringStartsWith('DR-', $perfil->driver_id);
    }

    public function test_si_la_persona_no_existe_en_el_sso_lo_dice_y_no_crea_nada(): void
    {
        Http::fake([
            self::SSO.'/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            self::SSO.'/api/v1/apps/users?email=*' => Http::response(['error' => 'not_found'], 404),
        ]);

        $this->comoOperaciones()->postJson('/api/treslog/drivers', ['email' => 'nadie@ejemplo.com'])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonFragment(['message' => 'Esa persona todavia no se registro en TR3SLOG. Pedile que ingrese una vez con su correo y volve a intentarlo.']);

        Http::assertNotSent(fn (PeticionHttp $p) => str_contains($p->url(), '/roles'));
        $this->assertSame(1, User::count());
        $this->assertSame(0, DriverProfile::count());
    }

    public function test_vincula_una_fila_local_por_correo_en_vez_de_duplicarla(): void
    {
        $this->ssoConPersona();
        $local = User::factory()->create(['email' => 'ana@ejemplo.com', 'name' => 'Ana (local)']);

        $this->comoOperaciones()->postJson('/api/treslog/drivers', ['email' => 'ana@ejemplo.com'])->assertStatus(201);

        $this->assertSame(2, User::count());
        $this->assertSame('55', $local->fresh()->sso_user_id);
        $this->assertSame('Ana Perez', $local->fresh()->name, 'el nombre del SSO manda');
    }

    public function test_al_vincular_una_fila_local_se_anula_su_contraseña_y_sus_tokens(): void
    {
        $this->ssoConPersona();
        $local = User::factory()->create(['email' => 'ana@ejemplo.com', 'password' => \Illuminate\Support\Facades\Hash::make('Vieja1!')]);
        $local->createToken('camino-viejo');
        $this->assertSame(1, $local->tokens()->count());

        $this->comoOperaciones()->postJson('/api/treslog/drivers', ['email' => 'ana@ejemplo.com'])->assertStatus(201);

        $this->assertFalse(\Illuminate\Support\Facades\Hash::check('Vieja1!', $local->fresh()->password), 'la contraseña vieja sigue valiendo');
        $this->assertSame(0, $local->fresh()->tokens()->count(), 'los tokens viejos siguen vivos');
    }

    public function test_si_el_sso_no_responde_es_un_502_y_no_queda_nada_a_medias(): void
    {
        Http::fake([
            self::SSO.'/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            self::SSO.'/api/v1/apps/users?email=*' => Http::response('', 503),
        ]);

        $this->comoOperaciones()->postJson('/api/treslog/drivers', ['email' => 'ana@ejemplo.com'])
            ->assertStatus(502)->assertJsonPath('success', false);

        $this->assertSame(1, User::count());
    }

    public function test_por_el_gateway_no_se_acepta_contraseña_ni_hace_falta(): void
    {
        $this->ssoConPersona();

        $this->comoOperaciones()->postJson('/api/treslog/drivers', ['email' => 'ana@ejemplo.com', 'password' => 'Secreta1!'])->assertStatus(201);

        $this->assertFalse(\Illuminate\Support\Facades\Hash::check('Secreta1!', (string) User::where('sso_user_id', '55')->first()->password));
    }

    public function test_solo_admin_u_operaciones(): void
    {
        $this->ssoConPersona();

        $this->withHeaders([
            'X-Auth-Gateway' => 'myglobalhub-gateway', 'X-User-Id' => '10', 'X-User-Clerk-Id' => 'user_clerk_10',
            'X-User-Email' => 'ops@tr3slog.test', 'X-User-Name' => 'Ops', 'X-User-Roles' => 'treslog:customer',
        ])->postJson('/api/treslog/drivers', ['email' => 'ana@ejemplo.com'])->assertStatus(403);

        Http::assertNothingSent();
    }
}
