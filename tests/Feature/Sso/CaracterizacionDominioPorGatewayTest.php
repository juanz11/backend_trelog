<?php

namespace Tests\Feature\Sso;

use App\Models\Quote;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Tarea 8.10: la MISMA persona, por los DOS caminos, recibe lo MISMO.
 *
 * Una fila de `users` que a la vez tiene su rol en `role_user` (camino viejo,
 * Sanctum) y su `sso_user_id` (camino nuevo, gateway con X-User-Roles). Cada
 * ruta de dominio se pide por los dos y se comparan codigo y cuerpo. Si el
 * Lote 8 cambio el comportamiento de alguna, aca se ve cual.
 *
 * Y los 403 de N1/D7 (crear cotizaciones, editar tickets: permisos que no tenia
 * NADIE) siguen siendo 403 por los dos caminos: no se «arreglan» solos.
 */
class CaracterizacionDominioPorGatewayTest extends TestCase
{
    use RefreshDatabase;

    private function delGateway(string $roles, string $ssoUserId): array
    {
        return [
            'X-Auth-Gateway'  => 'myglobalhub-gateway',
            'X-User-Id'       => $ssoUserId,
            'X-User-Clerk-Id' => 'user_clerk_'.$ssoUserId,
            'X-User-Email'    => "p{$ssoUserId}@tr3slog.test",
            'X-User-Name'     => 'Persona '.$ssoUserId,
            'X-User-Roles'    => $roles,
        ];
    }

    /** La misma persona con el rol en los dos mundos. */
    private function personaDoble(string $rolCorto, string $ssoUserId): User
    {
        $u = User::factory()->create(['email' => "p{$ssoUserId}@tr3slog.test", 'name' => 'Persona '.$ssoUserId]);
        $u->forceFill(['sso_user_id' => $ssoUserId])->save();

        if ($rolCorto !== 'customer') {
            $rol = Role::firstOrCreate(['name' => $rolCorto], ['display_name' => ucfirst($rolCorto)]);
            $u->roles()->attach($rol);
        }

        return $u->fresh();
    }

    private function porLosDosCaminos(User $u, string $rolCorto, string $metodo, string $ruta, array $datos = []): array
    {
        Sanctum::actingAs($u);
        $viejo = $this->json($metodo, '/api'.$ruta, $datos);

        $nuevo = $this->withHeaders($this->delGateway('treslog:'.$rolCorto, (string) $u->sso_user_id))
            ->json($metodo, '/api/treslog'.$ruta, $datos);

        return [$viejo, $nuevo];
    }

    /** @return array<string, array{string, string}> */
    public static function lecturasDeOperaciones(): array
    {
        return [
            'alertas'    => ['GET', '/alerts'],
            'choferes'   => ['GET', '/drivers'],
            'incidentes' => ['GET', '/incidents'],
            'cotizaciones' => ['GET', '/quotes'],
            'clientes'   => ['GET', '/users/clients'],
            'envios'     => ['GET', '/shipments'],
            'direcciones' => ['GET', '/addresses'],
            'soporte'    => ['GET', '/support'],
        ];
    }

    #[DataProvider('lecturasDeOperaciones')]
    public function test_un_admin_recibe_lo_mismo_por_los_dos_caminos(string $metodo, string $ruta): void
    {
        // Reloj congelado: algunas respuestas derivan campos de now() (el
        // tracking de los envios) y un segundo de diferencia entre las dos
        // llamadas no es una divergencia de comportamiento.
        $this->travelTo(now());

        $u = $this->personaDoble('admin', '4242');
        Shipment::forceCreate(['user_id' => $u->id, 'origin' => 'SDQ', 'destination' => 'STI', 'status' => 'pending']);
        Quote::forceCreate(['origin' => 'SDQ', 'destination' => 'PUJ', 'client_name' => 'C', 'client_email' => 'c@x.test']);

        [$viejo, $nuevo] = $this->porLosDosCaminos($u, 'admin', $metodo, $ruta);

        $this->assertSame($viejo->status(), $nuevo->status(), "«{$metodo} {$ruta}»: codigo distinto por el gateway");
        $this->assertSame(200, $viejo->status(), "«{$metodo} {$ruta}» ya no responde 200 a un admin por el camino viejo");
        $this->assertEquals($viejo->json(), $nuevo->json(), "«{$metodo} {$ruta}»: cuerpo distinto por el gateway");
    }

    public function test_un_cliente_ve_solo_sus_envios_por_los_dos_caminos(): void
    {
        $this->travelTo(now());

        $u    = $this->personaDoble('customer', '5151');
        $otro = User::factory()->create();
        $mio  = Shipment::forceCreate(['user_id' => $u->id, 'origin' => 'A', 'destination' => 'B', 'status' => 'pending']);
        Shipment::forceCreate(['user_id' => $otro->id, 'origin' => 'C', 'destination' => 'D', 'status' => 'pending']);

        [$viejo, $nuevo] = $this->porLosDosCaminos($u, 'customer', 'GET', '/shipments');

        $this->assertSame(200, $viejo->status());
        $this->assertSame(200, $nuevo->status());
        $this->assertEquals($viejo->json(), $nuevo->json());
        $this->assertCount(1, $nuevo->json());
        $this->assertSame($mio->id, $nuevo->json()[0]['id']);
    }

    public function test_un_cliente_no_entra_a_la_consola_por_ningun_camino(): void
    {
        $u = $this->personaDoble('customer', '5151');

        foreach (['/drivers', '/incidents', '/quotes', '/users/clients'] as $ruta) {
            [$viejo, $nuevo] = $this->porLosDosCaminos($u, 'customer', 'GET', $ruta);

            $this->assertSame(403, $viejo->status(), "«GET {$ruta}» dejo entrar a un cliente por Sanctum");
            $this->assertSame(403, $nuevo->status(), "«GET {$ruta}» dejo entrar a un cliente por el gateway");
        }
    }

    public function test_los_403_de_n1_siguen_siendo_403_por_los_dos_caminos(): void
    {
        // Operaciones NO puede cambiar el estado de una cotizacion ni editar un
        // ticket: nadie podia (N1, @todo D7). Por el camino nuevo pasa la guarda
        // de operaciones y lo frena la Policy, que quedo en isAdmin(). Si alguien
        // "arregla" esto dandole el permiso a operaciones, este test lo cuenta.
        $u = $this->personaDoble('operations', '6161');
        $ticket = SupportTicket::forceCreate(['user_id' => $u->id, 'subject' => 'S', 'message' => 'M']);
        $quote  = Quote::forceCreate(['origin' => 'A', 'destination' => 'B', 'client_name' => 'C', 'client_email' => 'c@x.test']);

        [$viejo, $nuevo] = $this->porLosDosCaminos($u, 'operations', 'PATCH', "/quotes/{$quote->id}/status", ['status' => 'approved']);
        $this->assertSame(403, $viejo->status(), 'PATCH /quotes/{id}/status: operaciones ya cambia cotizaciones por Sanctum (N1 se "arreglo" solo)');
        $this->assertSame(403, $nuevo->status(), 'PATCH /quotes/{id}/status: operaciones ya cambia cotizaciones por el gateway (N1 se "arreglo" solo)');

        [$viejo, $nuevo] = $this->porLosDosCaminos($u, 'operations', 'PUT', "/support/{$ticket->id}", ['status' => 'closed']);
        $this->assertSame(403, $viejo->status(), 'PUT /support: operaciones ya edita tickets por Sanctum');
        $this->assertSame(403, $nuevo->status(), 'PUT /support: operaciones ya edita tickets por el gateway');
    }

    public function test_crear_cotizaciones_desde_la_consola_nunca_estuvo_protegido_y_se_caracteriza_tal_cual(): void
    {
        // HALLAZGO de esta caracterizacion, no supuesto del diseño: 3-design.md
        // §D.5 da por hecho que `quotes.create` (N1) niega a todos, pero
        // QuoteController::store NUNCA llamo a authorize('create'): la Policy era
        // letra muerta ahi y operaciones creaba cotizaciones desde siempre. Este
        // lote no lo cambia (restringir en un PR de infraestructura es el cambio
        // escondido que §D.5 prohibe): se congela igual por los dos caminos y se
        // anota en D7. Si alguien agrega el authorize, este test lo cuenta.
        $u = $this->personaDoble('operations', '6161');

        [$viejo, $nuevo] = $this->porLosDosCaminos($u, 'operations', 'POST', '/quotes',
            ['origin' => 'A', 'destination' => 'B', 'client_name' => 'C', 'client_email' => 'c@x.test']);

        $this->assertSame(201, $viejo->status(), 'POST /quotes por Sanctum cambio de comportamiento para operaciones');
        $this->assertSame($viejo->status(), $nuevo->status(), 'POST /quotes: codigo distinto por el gateway');
    }
}
