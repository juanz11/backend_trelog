<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DriverProfile;
use App\Models\Permission;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DocumentReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $ops;
    private DriverProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'drivers.manage'], [
            'display_name' => 'drivers.manage', 'description' => 'drivers.manage', 'module' => 'dispatch',
        ]);

        $this->ops = User::factory()->create();
        $this->ops->givePermissionTo('drivers.manage');

        $driver = User::factory()->create(['name' => 'Chofer Demo']);
        $this->profile = DriverProfile::create([
            'user_id' => $driver->id,
            'driver_id' => 'DR-T00001',
            'initials' => 'CD',
            'available' => true,
        ]);
    }

    public function test_lista_documentos_pendientes(): void
    {
        $this->profile->documents()->create(['type' => 'id_card', 'number' => 'ID-9']);
        $vehicle = Vehicle::create(['driver_profile_id' => $this->profile->id, 'plate' => 'ABC-123', 'label' => 'Van']);
        $vehicle->documents()->create(['type' => 'insurance', 'number' => 'SEG-1', 'expires_at' => '2030-01-01']);
        $this->profile->documents()->create(['type' => 'license', 'status' => Document::STATUS_APPROVED]);

        Sanctum::actingAs($this->ops);
        $docs = $this->getJson('/api/documents/pending')->assertOk()->json();

        $this->assertCount(2, $docs);
        $this->assertEquals('id_card', $docs[0]['type']);
        $this->assertEquals('Chofer Demo', $docs[0]['driver_name']);
        $this->assertEquals('driver', $docs[0]['subject']);
        $this->assertEquals('insurance', $docs[1]['type']);
        $this->assertEquals('vehicle', $docs[1]['subject']);
        $this->assertEquals('DR-T00001', $docs[1]['driver_id']);
    }

    public function test_aprobar_y_rechazar_documento(): void
    {
        $idDoc = $this->profile->documents()->create(['type' => 'id_card', 'number' => 'ID-9']);
        $licDoc = $this->profile->documents()->create(['type' => 'license', 'number' => 'LIC-9']);

        Sanctum::actingAs($this->ops);

        // Rechazo sin motivo → 422
        $this->postJson("/api/documents/{$idDoc->id}/review", ['status' => 'rejected'])
            ->assertUnprocessable();

        $this->postJson("/api/documents/{$idDoc->id}/review", [
            'status' => 'rejected', 'reason' => 'Foto borrosa',
        ])->assertOk()->assertJsonPath('status', 'rejected');

        $this->assertEquals('rejected', $this->profile->fresh()->verification_status);

        // El aprobado queda pendiente mientras haya un rechazo
        $this->postJson("/api/documents/{$licDoc->id}/review", ['status' => 'approved'])
            ->assertOk()->assertJsonPath('status', 'approved');

        $this->assertEquals('rejected', $this->profile->fresh()->verification_status);
        $this->assertEquals($this->ops->id, $licDoc->fresh()->validated_by);
        $this->assertNotNull($licDoc->fresh()->validated_at);
    }

    public function test_perfil_verificado_cuando_todos_aprobados(): void
    {
        $doc = $this->profile->documents()->create(['type' => 'id_card']);

        Sanctum::actingAs($this->ops);
        $this->postJson("/api/documents/{$doc->id}/review", ['status' => 'approved'])->assertOk();

        $this->assertEquals('verified', $this->profile->fresh()->verification_status);
    }

    public function test_sin_permiso_no_puede_revisar(): void
    {
        $doc = $this->profile->documents()->create(['type' => 'id_card']);
        $user = User::factory()->create();

        Sanctum::actingAs($user);
        $this->getJson('/api/documents/pending')->assertForbidden();
        $this->postJson("/api/documents/{$doc->id}/review", ['status' => 'approved'])->assertForbidden();
    }
}
