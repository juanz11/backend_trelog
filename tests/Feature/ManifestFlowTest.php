<?php

namespace Tests\Feature;

use App\Models\DriverProfile;
use App\Models\Manifest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ManifestFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $ops;
    private User $driver;
    private User $replacement;
    private Vehicle $vehicle;
    private Vehicle $rescueVehicle;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['manifests.view', 'manifests.manage'] as $name) {
            Permission::firstOrCreate(['name' => $name], [
                'display_name' => $name, 'description' => $name, 'module' => 'manifests',
            ]);
        }
        Role::firstOrCreate(['name' => 'driver'], ['display_name' => 'driver']);

        $this->ops = User::factory()->create();
        $this->ops->givePermissionTo(['manifests.view', 'manifests.manage']);

        $this->driver = User::factory()->create();
        $this->driver->assignRole('driver');
        $this->replacement = User::factory()->create();
        $this->replacement->assignRole('driver');

        $p1 = DriverProfile::create(['user_id' => $this->driver->id]);
        $p2 = DriverProfile::create(['user_id' => $this->replacement->id]);
        $this->vehicle = Vehicle::create(['driver_profile_id' => $p1->id, 'label' => 'Van 04']);
        $this->rescueVehicle = Vehicle::create(['driver_profile_id' => $p2->id, 'label' => 'Van 07']);
    }

    private function asOps(): static
    {
        return Sanctum::actingAs($this->ops) && $this;
    }

    private function createManifest(): array
    {
        Sanctum::actingAs($this->ops);

        return $this->postJson('/api/manifests', [
            'codigo' => 'MAN-T-1',
            'stops' => [
                ['tracking_code' => 'TR3-A'],
                ['tracking_code' => 'TR3-B'],
                ['tracking_code' => 'TR3-C'],
                ['tracking_code' => 'TR3-D'],
            ],
        ])->assertCreated()->json();
    }

    public function test_ciclo_completo_con_traspaso_y_handover(): void
    {
        // Crear + asignar (chofer y vehículo obligatorios)
        $m = $this->createManifest();
        $this->assertEquals('CREADO', $m['estado']);
        $this->assertCount(4, $m['detalles']);

        Sanctum::actingAs($this->ops);
        $this->patchJson("/api/manifests/{$m['id']}/assignment", ['driver_id' => $this->driver->id])
            ->assertStatus(422); // sin vehículo → ASIGNACION_INCOMPLETA

        $m = $this->patchJson("/api/manifests/{$m['id']}/assignment", [
            'driver_id' => $this->driver->id,
            'vehicle_id' => $this->vehicle->id,
        ])->assertOk()->json();
        $this->assertEquals('ASIGNADO', $m['estado']);

        // Chofer rechaza → vuelve a CREADO
        Sanctum::actingAs($this->driver);
        $m = $this->postJson("/api/driver/manifests/{$m['id']}/assignment-response", [
            'accept' => false, 'reason' => 'Vehículo full',
        ])->assertOk()->json();
        $this->assertEquals('CREADO', $m['estado']);
        $this->assertNull($m['choferId']);

        // Reasignar y arrancar ruta
        Sanctum::actingAs($this->ops);
        $this->patchJson("/api/manifests/{$m['id']}/assignment", [
            'driver_id' => $this->driver->id, 'vehicle_id' => $this->vehicle->id,
        ])->assertOk();

        Sanctum::actingAs($this->driver);
        $m = $this->postJson("/api/driver/manifests/{$m['id']}/start")->assertOk()->json();
        $this->assertEquals('EN_RUTA', $m['estado']);

        // POD: validaciones
        $this->postJson("/api/driver/manifests/{$m['id']}/deliveries", [
            'code' => 'TR3-ZZZ', 'evidence_url' => 's3://x.jpg',
        ])->assertStatus(422)->assertJsonPath('code', 'QR_NO_PERTENECE');

        $this->postJson("/api/driver/manifests/{$m['id']}/deliveries", [
            'code' => 'TR3-B', 'evidence_url' => 's3://x.jpg',
        ])->assertStatus(422)->assertJsonPath('code', 'PARADA_INCORRECTA');

        $this->postJson("/api/driver/manifests/{$m['id']}/deliveries", [
            'code' => 'TR3-A',
        ])->assertStatus(422)->assertJsonPath('code', 'SIN_EVIDENCIA');

        // Entrega con GPS + evidencia (vía manual = fallback QR ilegible)
        $m = $this->postJson("/api/driver/manifests/{$m['id']}/deliveries", [
            'code' => 'TR3-A', 'via' => 'MANUAL',
            'evidence_url' => 's3://pod/tr3-a.jpg', 'lat' => 18.4655, 'lng' => -66.1057,
        ])->assertOk()->json();
        $this->assertEquals('ENTREGADO', $m['detalles'][0]['estado']);
        $this->assertEquals(18.4655, $m['detalles'][0]['lat']);
        $this->assertEquals('MANUAL', $m['detalles'][0]['capturaVia']);

        // Incidencia sin motivo / con motivo
        $this->postJson("/api/driver/manifests/{$m['id']}/failures", [
            'detail_id' => $m['detalles'][1]['id'],
        ])->assertStatus(422);
        $m = $this->postJson("/api/driver/manifests/{$m['id']}/failures", [
            'detail_id' => $m['detalles'][1]['id'], 'reason' => 'Cliente ausente',
        ])->assertOk()->json();
        $this->assertEquals('NO_ENTREGADO', $m['detalles'][1]['estado']);
        $this->assertEquals(0.5, $m['progreso']);

        // Finalizar con pendientes → error
        $this->postJson("/api/driver/manifests/{$m['id']}/finalize")
            ->assertStatus(422)->assertJsonPath('code', 'PEDIDOS_PENDIENTES');

        // Traspaso parcial: solo detalle 3 (TR3-C); TR3-D queda fuera
        $sol = $this->postJson("/api/driver/manifests/{$m['id']}/transfer-requests", [
            'reason' => 'Avería mecánica', 'detail_ids' => [$m['detalles'][2]['id']],
        ])->assertCreated()->json();
        $this->assertEquals('EN_SOLICITUD_TRASPASO', $sol['manifiesto']['estado']);
        $solId = $sol['solicitud']['id'];

        // Operaciones rechaza → EN_RUTA con asistencia pendiente
        Sanctum::actingAs($this->ops);
        $m = $this->postJson("/api/transfer-requests/{$solId}/resolve", [
            'approve' => false, 'reject_reason' => 'Sin flota cercana',
        ])->assertOk()->json();
        $this->assertEquals('EN_RUTA', $m['estado']);
        $this->assertTrue($m['asistenciaVialPendiente']);

        Sanctum::actingAs($this->driver);
        $this->postJson("/api/driver/manifests/{$m['id']}/finalize")
            ->assertStatus(422)->assertJsonPath('code', 'ASISTENCIA_PENDIENTE');
        $m = $this->postJson("/api/driver/manifests/{$m['id']}/assistance-resolved")->assertOk()->json();
        $this->assertFalse($m['asistenciaVialPendiente']);

        // Nueva solicitud → aprobada → manifiesto derivado
        $sol = $this->postJson("/api/driver/manifests/{$m['id']}/transfer-requests", [
            'reason' => 'Avería mecánica', 'detail_ids' => [$m['detalles'][2]['id']],
        ])->assertCreated()->json();

        Sanctum::actingAs($this->ops);
        $res = $this->postJson("/api/transfer-requests/{$sol['solicitud']['id']}/resolve", [
            'approve' => true,
            'replacement_driver_id' => $this->replacement->id,
            'replacement_vehicle_id' => $this->rescueVehicle->id,
        ])->assertOk()->json();

        $orig = $res['original'];
        $der = $res['derivado'];
        $this->assertEquals('TRANSFERIDO', $orig['estado']);
        $this->assertEquals('ENTREGADO', $orig['detalles'][0]['estado']);      // congelado
        $this->assertEquals('NO_ENTREGADO', $orig['detalles'][1]['estado']);   // fallo conservado
        $this->assertEquals('TRANSFERIDO', $orig['detalles'][2]['estado']);    // seleccionado
        $this->assertEquals('NO_ENTREGADO', $orig['detalles'][3]['estado']);   // no seleccionado

        $this->assertEquals('ASIGNADO', $der['estado']);
        $this->assertEquals('MAN-T-1-D1', $der['codigo']);
        $this->assertEquals($orig['id'], $der['idManifiestoOrigen']);
        $this->assertEquals($this->replacement->id, $der['choferId']);
        $this->assertCount(1, $der['detalles']);
        $this->assertEquals('TR3-C', $der['detalles'][0]['codigoSeguimiento']);

        // Handover físico: scan-in sin out → discrepancia; luego correcto
        Sanctum::actingAs($this->replacement);
        $h = $this->postJson("/api/driver/manifests/{$der['id']}/handover/scan", [
            'code' => 'TR3-C', 'phase' => 'in',
        ])->assertOk()->json();
        $this->assertEquals('SIN_SCAN_OUT', $h['discrepancias'][0]['motivo']);

        // El chofer origen también puede escanear (scan-out tardío)
        Sanctum::actingAs($this->driver);
        $this->postJson("/api/driver/manifests/{$der['id']}/handover/scan", [
            'code' => 'TR3-C', 'phase' => 'out',
        ])->assertOk();

        Sanctum::actingAs($this->replacement);
        $h = $this->postJson("/api/driver/manifests/{$der['id']}/handover/scan", [
            'code' => 'TR3-AJENO', 'phase' => 'in',
        ])->assertOk()->json();
        $this->assertContains('PAQUETE_NO_ESPERADO', array_column($h['discrepancias'], 'motivo'));

        // El derivado completa la ruta → COMPLETADO
        $der = $this->postJson("/api/driver/manifests/{$der['id']}/start")->assertOk()->json();
        $der = $this->postJson("/api/driver/manifests/{$der['id']}/deliveries", [
            'code' => 'TR3-C', 'evidence_url' => 's3://pod/tr3-c.jpg', 'lat' => 18.4, 'lng' => -66.0,
        ])->assertOk()->json();
        $der = $this->postJson("/api/driver/manifests/{$der['id']}/finalize")->assertOk()->json();
        $this->assertEquals('COMPLETADO', $der['estado']);

        // Persistencia final
        $this->assertDatabaseHas('manifests', ['id' => $orig['id'], 'status' => 'TRANSFERIDO']);
        $this->assertDatabaseHas('manifest_details', [
            'manifest_id' => $der['id'], 'status' => 'ENTREGADO',
        ]);
    }

    public function test_cierre_limpio_sin_incidencias(): void
    {
        $m = $this->createManifest();
        Sanctum::actingAs($this->ops);
        $this->patchJson("/api/manifests/{$m['id']}/assignment", [
            'driver_id' => $this->driver->id, 'vehicle_id' => $this->vehicle->id,
        ]);
        Sanctum::actingAs($this->driver);
        $this->postJson("/api/driver/manifests/{$m['id']}/start");

        $m = $this->getJson("/api/driver/manifests/{$m['id']}")->json();
        foreach ($m['detalles'] as $i => $d) {
            // strict=false para entregar fuera de orden libremente
            $m = $this->postJson("/api/driver/manifests/{$m['id']}/deliveries", [
                'code' => $d['codigoSeguimiento'], 'evidence_url' => 's3://pod/x.jpg', 'strict' => false,
            ])->assertOk()->json();
        }
        $m = $this->postJson("/api/driver/manifests/{$m['id']}/finalize")->assertOk()->json();
        $this->assertEquals('COMPLETADO', $m['estado']);
    }

    public function test_driver_no_puede_operar_manifiesto_ajeno(): void
    {
        $m = $this->createManifest();
        Sanctum::actingAs($this->ops);
        $this->patchJson("/api/manifests/{$m['id']}/assignment", [
            'driver_id' => $this->driver->id, 'vehicle_id' => $this->vehicle->id,
        ]);
        Sanctum::actingAs($this->replacement);
        $this->postJson("/api/driver/manifests/{$m['id']}/start")->assertForbidden();
    }
}
