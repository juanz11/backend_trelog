<?php

namespace Tests\Feature\Sso;

use App\Models\DriverProfile;
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

    private function ssoConPersona(array $persona = ['id' => '55', 'email' => 'ana@ejemplo.com', 'name' => 'Ana Perez', 'is_active' => true, 'roles' => ['treslog:customer']], bool $creada = false): void
    {
        Http::fake([
            self::SSO.'/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            self::SSO.'/api/v1/apps/users' => Http::response(['data' => array_merge($persona, ['roles' => ['treslog:customer', 'treslog:driver']]), 'created' => $creada, 'access' => $creada ? 'pending' : 'active'], $creada ? 202 : 200),
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
        Http::assertSent(fn (PeticionHttp $p) => $p->url() === self::SSO.'/api/v1/apps/users' && $p->hasHeader('Authorization', 'Bearer tok') && $p['email'] === 'Ana@Ejemplo.com' && $p['role'] === 'treslog:driver');

        $espejo = User::where('sso_user_id', '55')->first();
        $this->assertNotNull($espejo);
        $this->assertSame('ana@ejemplo.com', $espejo->email);
        $this->assertSame('+58 424', $espejo->phone);
        // Sin contraseña ni rol local (Lote 9): lo que la hace conductora para la
        // consola es el DriverProfile; el rol `treslog:driver` lo da el SSO.
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('users', 'password'));
        $perfil = DriverProfile::where('user_id', $espejo->id)->first();
        $this->assertSame('Van 12', $perfil->vehicle);
        $this->assertSame('AP', $perfil->initials);
        $this->assertStringStartsWith('DR-', $perfil->driver_id);
    }

    public function test_si_la_persona_no_existe_el_sso_la_invita_y_aca_queda_espejo_y_perfil_pendientes(): void
    {
        $this->ssoConPersona(['id' => '77', 'email' => 'nuevo@ejemplo.com', 'name' => 'Nuevo Conductor', 'is_active' => true, 'roles' => []], creada: true);

        $r = $this->comoOperaciones()->postJson('/api/treslog/drivers', ['email' => 'nuevo@ejemplo.com', 'name' => 'Nuevo Conductor', 'vehicle' => 'Moto 3']);

        $r->assertStatus(201)->assertJsonPath('invited', true)->assertJsonPath('email', 'nuevo@ejemplo.com');
        Http::assertSent(fn (PeticionHttp $p) => $p->url() === self::SSO.'/api/v1/apps/users' && $p['first_name'] === 'Nuevo Conductor');
        $espejo = User::where('sso_user_id', '77')->first();
        $this->assertNotNull($espejo);
        $this->assertSame('Moto 3', DriverProfile::where('user_id', $espejo->id)->value('vehicle'));
    }

    public function test_si_el_sso_rechaza_la_invitacion_lo_dice_y_no_crea_nada(): void
    {
        Http::fake([
            self::SSO.'/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            self::SSO.'/api/v1/apps/users' => Http::response(['error' => 'validation_failed', 'message' => 'Clerk rechazo', 'errors' => ['email' => ['Ya hay una invitacion en curso']]], 422),
        ]);

        $this->comoOperaciones()->postJson('/api/treslog/drivers', ['email' => 'nadie@ejemplo.com'])->assertStatus(502);

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

    public function test_si_el_sso_no_responde_es_un_502_y_no_queda_nada_a_medias(): void
    {
        Http::fake([
            self::SSO.'/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            self::SSO.'/api/v1/apps/users' => Http::response('', 503),
        ]);

        $this->comoOperaciones()->postJson('/api/treslog/drivers', ['email' => 'ana@ejemplo.com'])
            ->assertStatus(502)->assertJsonPath('success', false);

        $this->assertSame(1, User::count());
    }

    public function test_una_contraseña_en_la_peticion_se_ignora_porque_no_hay_donde_guardarla(): void
    {
        $this->ssoConPersona();

        $this->comoOperaciones()->postJson('/api/treslog/drivers', ['email' => 'ana@ejemplo.com', 'password' => 'Secreta1!'])->assertStatus(201);

        $this->assertNotNull(User::where('sso_user_id', '55')->first());
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('users', 'password'));
    }

    public function test_el_listado_de_conductores_es_quien_tiene_perfil_de_conductor(): void
    {
        $this->ssoConPersona();
        $this->comoOperaciones()->postJson('/api/treslog/drivers', ['email' => 'ana@ejemplo.com', 'vehicle' => 'Van 12'])->assertStatus(201);
        User::factory()->create(['email' => 'cliente@ejemplo.com']);

        $lista = $this->comoOperaciones()->getJson('/api/treslog/drivers')->assertOk()->json();

        $this->assertCount(1, $lista);
        $this->assertSame('ana@ejemplo.com', $lista[0]['email']);
        $this->assertSame('Van 12', $lista[0]['v']);
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
