<?php

namespace Tests\Feature\Sso;

use App\Models\Address;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tarea 8.7: la PERTENENCIA la siguen decidiendo las Policies (y
 * `authorizeOwner()` en direcciones) exactamente igual que antes de tocar los
 * roles, por los DOS caminos. Lo mio, 200; lo ajeno, 403; y admin ve todo.
 *
 * Es lo que el SSO no puede hacer por nosotros (3-design.md §D.4): el rol dice
 * que sos cliente, no de quien es cada envio.
 */
class PoliciesSiguenIgualesTest extends TestCase
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

    private function persona(string $ssoUserId, ?string $rolViejo = null): User
    {
        $u = User::factory()->create(['email' => "p{$ssoUserId}@tr3slog.test"]);
        $u->forceFill(['sso_user_id' => $ssoUserId])->save();

        if ($rolViejo !== null) {
            $u->roles()->attach(Role::firstOrCreate(['name' => $rolViejo], ['display_name' => ucfirst($rolViejo)]));
        }

        return $u->fresh();
    }

    private function viejo(User $u, string $ruta)
    {
        Sanctum::actingAs($u);

        return $this->getJson('/api'.$ruta);
    }

    private function nuevo(User $u, string $rol, string $ruta)
    {
        return $this->withHeaders($this->delGateway('treslog:'.$rol, (string) $u->sso_user_id))->getJson('/api/treslog'.$ruta);
    }

    /** @return array{User, User, array<string, int>} cliente, otro cliente, ids de lo del OTRO */
    private function escenario(): array
    {
        $yo   = $this->persona('7001');
        $otro = $this->persona('7002');

        $ajeno = [
            'envio'     => Shipment::forceCreate(['user_id' => $otro->id, 'origin' => 'A', 'destination' => 'B', 'status' => 'pending'])->id,
            'direccion' => Address::forceCreate(['user_id' => $otro->id, 'address' => 'Calle 1', 'type' => 'delivery'])->id,
            'ticket'    => SupportTicket::forceCreate(['user_id' => $otro->id, 'subject' => 'S', 'message' => 'M'])->id,
        ];

        $mio = [
            'envio'     => Shipment::forceCreate(['user_id' => $yo->id, 'origin' => 'C', 'destination' => 'D', 'status' => 'pending'])->id,
            'direccion' => Address::forceCreate(['user_id' => $yo->id, 'address' => 'Calle 2', 'type' => 'delivery'])->id,
            'ticket'    => SupportTicket::forceCreate(['user_id' => $yo->id, 'subject' => 'S2', 'message' => 'M2'])->id,
        ];

        return [$yo, $otro, $ajeno, $mio];
    }

    public function test_lo_mio_200_y_lo_ajeno_403_por_los_dos_caminos(): void
    {
        [$yo, , $ajeno, $mio] = $this->escenario();

        $casos = [
            "/shipments/{$mio['envio']}"      => 200, "/shipments/{$ajeno['envio']}"      => 403,
            "/addresses/{$mio['direccion']}"  => 200, "/addresses/{$ajeno['direccion']}"  => 403,
            "/support/{$mio['ticket']}"       => 200, "/support/{$ajeno['ticket']}"       => 403,
            "/users/{$yo->id}"                => 200,
        ];

        foreach ($casos as $ruta => $esperado) {
            $this->assertSame($esperado, $this->viejo($yo, $ruta)->status(), "Sanctum «GET {$ruta}»");
            $this->assertSame($esperado, $this->nuevo($yo, 'customer', $ruta)->status(), "gateway «GET {$ruta}»");
        }
    }

    public function test_ver_a_otra_persona_es_403_salvo_que_seas_admin(): void
    {
        [$yo, $otro] = $this->escenario();

        $this->assertSame(403, $this->viejo($yo, "/users/{$otro->id}")->status());
        $this->assertSame(403, $this->nuevo($yo, 'customer', "/users/{$otro->id}")->status());

        $admin = $this->persona('7003', 'admin');
        $this->assertSame(200, $this->viejo($admin, "/users/{$otro->id}")->status());
        $this->assertSame(200, $this->nuevo($admin, 'admin', "/users/{$otro->id}")->status());
    }

    public function test_admin_ve_lo_ajeno_por_los_dos_caminos(): void
    {
        [, , $ajeno] = $this->escenario();
        $admin = $this->persona('7003', 'admin');

        foreach (["/shipments/{$ajeno['envio']}", "/addresses/{$ajeno['direccion']}", "/support/{$ajeno['ticket']}"] as $ruta) {
            $this->assertSame(200, $this->viejo($admin, $ruta)->status(), "Sanctum admin «GET {$ruta}»");
            $this->assertSame(200, $this->nuevo($admin, 'admin', $ruta)->status(), "gateway admin «GET {$ruta}»");
        }
    }

    public function test_operaciones_ve_envios_ajenos_pero_no_tickets_ajenos(): void
    {
        // ShipmentPolicy::view: operaciones o dueño. SupportTicketPolicy::view:
        // admin o dueño — operaciones NO. Asi era, asi queda, por los dos caminos.
        [, , $ajeno] = $this->escenario();
        $ops = $this->persona('7004', 'operations');

        $this->assertSame(200, $this->viejo($ops, "/shipments/{$ajeno['envio']}")->status());
        $this->assertSame(200, $this->nuevo($ops, 'operations', "/shipments/{$ajeno['envio']}")->status());

        $this->assertSame(403, $this->viejo($ops, "/support/{$ajeno['ticket']}")->status());
        $this->assertSame(403, $this->nuevo($ops, 'operations', "/support/{$ajeno['ticket']}")->status());
    }
}
