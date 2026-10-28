<?php

namespace Tests\Feature;

use App\Models\Manifest;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * DCU-08 Hub y almacén: recepción contra manifiesto, escaneo de
 * entrada/salida, diferencias que bloquean el despacho y ajustes
 * aprobados por supervisor.
 */
class HubManifestFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $ops;
    private User $operator;
    private User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['manifests.view', 'manifests.manage', 'hub.view', 'hub.manage', 'hub.adjustments.approve'] as $name) {
            Permission::firstOrCreate(['name' => $name], [
                'display_name' => $name, 'description' => $name, 'module' => 'hub',
            ]);
        }

        $this->ops = User::factory()->create();
        $this->ops->givePermissionTo(['manifests.view', 'manifests.manage', 'hub.view']);

        $this->operator = User::factory()->create();
        $this->operator->givePermissionTo(['hub.view', 'hub.manage', 'manifests.view']);

        $this->supervisor = User::factory()->create();
        $this->supervisor->givePermissionTo(['hub.view', 'hub.adjustments.approve', 'manifests.view']);
    }

    private function createManifest(): array
    {
        Sanctum::actingAs($this->ops);

        return $this->postJson('/api/manifests', [
            'codigo' => 'MAN-HUB-1',
            'stops' => [
                ['tracking_code' => 'TR3-H1'],
                ['tracking_code' => 'TR3-H2'],
                ['tracking_code' => 'TR3-H3'],
            ],
        ])->assertCreated()->json();
    }

    public function test_recepcion_despacho_limpio(): void
    {
        $m = $this->createManifest();

        Sanctum::actingAs($this->operator);
        foreach (['TR3-H1', 'TR3-H2', 'TR3-H3'] as $code) {
            $this->postJson("/api/hub/manifests/{$m['id']}/scan", [
                'codigo' => $code, 'phase' => 'in',
            ])->assertCreated();
        }

        // La primera entrada marca la recepción; despachar sin escaneos
        // de salida queda bloqueado por diferencias (pendientes de salida).
        $this->postJson("/api/hub/manifests/{$m['id']}/dispatch")
            ->assertStatus(422)->assertJsonPath('code', 'DESPACHO_BLOQUEADO');

        $this->postJson("/api/hub/manifests/{$m['id']}/receive")->assertOk();

        // Escaneo de salida por paquete
        foreach (['TR3-H1', 'TR3-H2', 'TR3-H3'] as $code) {
            $this->postJson("/api/hub/manifests/{$m['id']}/scan", [
                'codigo' => $code, 'phase' => 'out',
            ])->assertCreated();
        }

        $res = $this->postJson("/api/hub/manifests/{$m['id']}/dispatch")->assertOk()->json();
        $this->assertNotNull($res['conciliacion']['despachadoAt']);
        $this->assertFalse($res['conciliacion']['bloqueado']);
    }

    public function test_diferencias_bloquean_despacho_hasta_ajuste_aprobado(): void
    {
        $m = $this->createManifest();

        Sanctum::actingAs($this->operator);
        // Entran solo 2 de 3 esperados + 1 paquete ajeno
        $this->postJson("/api/hub/manifests/{$m['id']}/scan", ['codigo' => 'TR3-H1', 'phase' => 'in']);
        $this->postJson("/api/hub/manifests/{$m['id']}/scan", ['codigo' => 'TR3-H2', 'phase' => 'in']);
        $this->postJson("/api/hub/manifests/{$m['id']}/scan", ['codigo' => 'TR3-XX', 'phase' => 'in']);
        $this->postJson("/api/hub/manifests/{$m['id']}/receive")->assertOk();
        $this->postJson("/api/hub/manifests/{$m['id']}/scan", ['codigo' => 'TR3-H1', 'phase' => 'out']);
        $this->postJson("/api/hub/manifests/{$m['id']}/scan", ['codigo' => 'TR3-H2', 'phase' => 'out']);

        $show = $this->getJson("/api/hub/manifests/{$m['id']}")->assertOk()->json();
        $this->assertEquals(['TR3-H3'], $show['conciliacion']['faltantesEntrada']);
        $this->assertEquals(['TR3-XX'], $show['conciliacion']['noEsperados']);
        $this->assertTrue($show['conciliacion']['bloqueado']);

        // Despacho bloqueado por diferencias
        $this->postJson("/api/hub/manifests/{$m['id']}/dispatch")
            ->assertStatus(422)->assertJsonPath('code', 'DESPACHO_BLOQUEADO');

        // Operario registra ajustes (solo anexado)
        $a1 = $this->postJson("/api/hub/manifests/{$m['id']}/adjustments", [
            'codigo' => 'TR3-H3', 'type' => 'FALTANTE', 'reason' => 'No llegó en el camión',
        ])->assertCreated()->json();
        $a2 = $this->postJson("/api/hub/manifests/{$m['id']}/adjustments", [
            'codigo' => 'TR3-XX', 'type' => 'SOBRANTE', 'reason' => 'Paquete sin registro en manifiesto',
        ])->assertCreated()->json();

        // Ajuste pendiente sigue bloqueando
        $this->postJson("/api/hub/manifests/{$m['id']}/dispatch")
            ->assertStatus(422)->assertJsonPath('code', 'DESPACHO_BLOQUEADO');

        // El operario NO puede aprobar sus propios ajustes
        $this->postJson("/api/hub/adjustments/{$a1['id']}/resolve", ['approve' => true])
            ->assertForbidden();

        // Supervisor aprueba ambos → se desbloquea
        Sanctum::actingAs($this->supervisor);
        $this->postJson("/api/hub/adjustments/{$a1['id']}/resolve", [
            'approve' => true, 'reason' => 'Verificado en cámaras',
        ])->assertOk();
        $this->postJson("/api/hub/adjustments/{$a2['id']}/resolve", [
            'approve' => true,
        ])->assertOk();

        Sanctum::actingAs($this->operator);
        $res = $this->postJson("/api/hub/manifests/{$m['id']}/dispatch")->assertOk()->json();
        $this->assertFalse($res['conciliacion']['bloqueado']);
    }

    public function test_ajuste_rechazado_mantiene_bloqueo(): void
    {
        $m = $this->createManifest();

        Sanctum::actingAs($this->operator);
        $this->postJson("/api/hub/manifests/{$m['id']}/scan", ['codigo' => 'TR3-H1', 'phase' => 'in']);
        $this->postJson("/api/hub/manifests/{$m['id']}/receive")->assertOk();
        $this->postJson("/api/hub/manifests/{$m['id']}/scan", ['codigo' => 'TR3-H1', 'phase' => 'out']);

        // TR3-H2 y TR3-H3 faltan → un solo ajuste no basta
        $a = $this->postJson("/api/hub/manifests/{$m['id']}/adjustments", [
            'codigo' => 'TR3-H2', 'type' => 'FALTANTE', 'reason' => 'Extraviado',
        ])->assertCreated()->json();

        Sanctum::actingAs($this->supervisor);
        $this->postJson("/api/hub/adjustments/{$a['id']}/resolve", [
            'approve' => false, 'reason' => 'Sin evidencia',
        ])->assertOk();

        Sanctum::actingAs($this->operator);
        $this->postJson("/api/hub/manifests/{$m['id']}/dispatch")
            ->assertStatus(422)->assertJsonPath('code', 'DESPACHO_BLOQUEADO');

        // Ajuste aprobado cubre TR3-H2; falta TR3-H3
        $a2 = $this->postJson("/api/hub/manifests/{$m['id']}/adjustments", [
            'codigo' => 'TR3-H2', 'type' => 'FALTANTE', 'reason' => 'Confirmado extraviado',
        ])->assertCreated()->json();
        Sanctum::actingAs($this->supervisor);
        $this->postJson("/api/hub/adjustments/{$a2['id']}/resolve", ['approve' => true])->assertOk();

        Sanctum::actingAs($this->operator);
        $this->postJson("/api/hub/manifests/{$m['id']}/dispatch")
            ->assertStatus(422); // TR3-H3 sigue sin cubrir

        $a3 = $this->postJson("/api/hub/manifests/{$m['id']}/adjustments", [
            'codigo' => 'TR3-H3', 'type' => 'FALTANTE', 'reason' => 'Devuelto a remitente',
        ])->assertCreated()->json();
        Sanctum::actingAs($this->supervisor);
        $this->postJson("/api/hub/adjustments/{$a3['id']}/resolve", ['approve' => true])->assertOk();

        Sanctum::actingAs($this->operator);
        $this->postJson("/api/hub/manifests/{$m['id']}/dispatch")->assertOk();
    }

    public function test_sin_permiso_hub_no_puede_escanear(): void
    {
        $m = $this->createManifest();

        // ops tiene hub.view pero no hub.manage
        Sanctum::actingAs($this->ops);
        $this->getJson("/api/hub/manifests/{$m['id']}")->assertOk();
        $this->postJson("/api/hub/manifests/{$m['id']}/scan", [
            'codigo' => 'TR3-H1', 'phase' => 'in',
        ])->assertForbidden();
    }
}
