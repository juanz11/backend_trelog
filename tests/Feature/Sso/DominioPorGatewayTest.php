<?php

namespace Tests\Feature\Sso;

use App\Models\Quote;
use App\Models\Shipment;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * El dominio de la web por el UNICO camino que queda (Lote 9): el gateway.
 *
 * Hasta el Lote 9 este archivo era CaracterizacionDominioPorGatewayTest y
 * comparaba cada ruta por los dos caminos (Sanctum vs gateway) para probar que
 * el Lote 8 no habia cambiado nada. El camino viejo murio; lo que se congelo
 * entonces se conserva aca como comportamiento esperado del camino nuevo.
 *
 * Los 403 de N1/D7 (crear cotizaciones, editar tickets: permisos que no tenia
 * NADIE) siguen siendo 403: no se «arreglan» solos.
 */
class DominioPorGatewayTest extends TestCase
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

    /** Una fila espejo anclada al SSO. Sin rol local: ya no existe tal cosa. */
    private function persona(string $ssoUserId): User
    {
        $u = User::factory()->create(['email' => "p{$ssoUserId}@tr3slog.test", 'name' => 'Persona '.$ssoUserId]);
        $u->forceFill(['sso_user_id' => $ssoUserId])->save();

        return $u->fresh();
    }

    private function porElGateway(User $u, string $rolCorto, string $metodo, string $ruta, array $datos = []): TestResponse
    {
        return $this->withHeaders($this->delGateway('treslog:'.$rolCorto, (string) $u->sso_user_id))
            ->json($metodo, '/api/treslog'.$ruta, $datos);
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
    public function test_un_admin_lee_toda_la_consola(string $metodo, string $ruta): void
    {
        $u = $this->persona('4242');
        Shipment::forceCreate(['user_id' => $u->id, 'origin' => 'SDQ', 'destination' => 'STI', 'status' => 'pending']);
        Quote::forceCreate(['origin' => 'SDQ', 'destination' => 'PUJ', 'client_name' => 'C', 'client_email' => 'c@x.test']);

        $this->porElGateway($u, 'admin', $metodo, $ruta)->assertOk();
    }

    public function test_un_cliente_ve_solo_sus_envios(): void
    {
        $u    = $this->persona('5151');
        $otro = User::factory()->create();
        $mio  = Shipment::forceCreate(['user_id' => $u->id, 'origin' => 'A', 'destination' => 'B', 'status' => 'pending']);
        Shipment::forceCreate(['user_id' => $otro->id, 'origin' => 'C', 'destination' => 'D', 'status' => 'pending']);

        $r = $this->porElGateway($u, 'customer', 'GET', '/shipments')->assertOk();

        $this->assertCount(1, $r->json());
        $this->assertSame($mio->id, $r->json()[0]['id']);
    }

    public function test_un_cliente_no_entra_a_la_consola(): void
    {
        $u = $this->persona('5151');

        foreach (['/drivers', '/incidents', '/quotes', '/users/clients'] as $ruta) {
            $this->assertSame(403, $this->porElGateway($u, 'customer', 'GET', $ruta)->status(), "«GET {$ruta}» dejo entrar a un cliente");
        }
    }

    public function test_los_403_de_n1_siguen_siendo_403(): void
    {
        // Operaciones NO puede cambiar el estado de una cotizacion ni editar un
        // ticket: nadie podia (N1, @todo D7). Pasa la guarda de operaciones y lo
        // frena la Policy, que quedo en isAdmin(). Si alguien "arregla" esto
        // dandole el permiso a operaciones, este test lo cuenta.
        $u = $this->persona('6161');
        $ticket = SupportTicket::forceCreate(['user_id' => $u->id, 'subject' => 'S', 'message' => 'M']);
        $quote  = Quote::forceCreate(['origin' => 'A', 'destination' => 'B', 'client_name' => 'C', 'client_email' => 'c@x.test']);

        $this->assertSame(403, $this->porElGateway($u, 'operations', 'PATCH', "/quotes/{$quote->id}/status", ['status' => 'approved'])->status());
        $this->assertSame(403, $this->porElGateway($u, 'operations', 'PUT', "/support/{$ticket->id}", ['status' => 'closed'])->status());
    }

    public function test_crear_cotizaciones_desde_la_consola_nunca_estuvo_protegido_y_se_conserva_tal_cual(): void
    {
        // HALLAZGO de la caracterizacion del Lote 8, no supuesto del diseño:
        // QuoteController::store NUNCA llamo a authorize('create') y operaciones
        // creaba cotizaciones desde siempre. Se congelo asi y se anoto en D7. Si
        // alguien agrega el authorize, este test lo cuenta.
        $u = $this->persona('6161');

        $this->porElGateway($u, 'operations', 'POST', '/quotes',
            ['origin' => 'A', 'destination' => 'B', 'client_name' => 'C', 'client_email' => 'c@x.test'])
            ->assertStatus(201);
    }

    public function test_la_foto_de_roles_se_escribe_al_entrar_y_es_lo_que_lista_a_los_clientes(): void
    {
        // `sso_roles` (Lote 9): ResolveDomainUser deja en la fila los ultimos
        // roles que emitio el SSO. Es lo que hace que un cliente aparezca en el
        // selector de clientes de la consola sin que exista `role_user`.
        $cliente = $this->persona('7171');
        $this->assertNull($cliente->sso_roles);

        $this->porElGateway($cliente, 'customer', 'GET', '/me')->assertOk();
        $this->assertSame(['treslog:customer'], $cliente->fresh()->sso_roles);

        $admin = $this->persona('4242');
        $lista = $this->porElGateway($admin, 'admin', 'GET', '/users/clients')->assertOk()->json();
        $this->assertSame([$cliente->id], array_column($lista, 'id'));

        // Si el SSO le quita el rol, la proxima entrada actualiza la foto y desaparece.
        $this->withHeaders($this->delGateway('', '7171'))->getJson('/api/treslog/me')->assertOk();
        $this->assertSame([], $cliente->fresh()->sso_roles);
        $this->assertSame([], $this->porElGateway($admin, 'admin', 'GET', '/users/clients')->json());
    }
}
