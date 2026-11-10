<?php

namespace Tests\Feature;

use App\Models\LegalDocument;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShipmentFragileTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private LegalDocument $doc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->doc = LegalDocument::where('type', 'prohibited_items')->firstOrFail();
    }

    public function test_fragile_flag_is_persisted_per_package(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/shipments', [
            'prohibited_accepted' => true,
            'prohibited_document_id' => $this->doc->id,
            'packages' => [
                ['type' => 'box', 'weight' => 2, 'pieces' => '1', 'fragile' => true],
                ['type' => 'package', 'weight' => 5, 'pieces' => '2', 'fragile' => false],
            ],
        ]);

        $response->assertStatus(201);

        $shipment = Shipment::findOrFail($response->json('id'));

        $items = $shipment->packageItems()->orderBy('id')->get();
        $this->assertCount(2, $items);
        $this->assertTrue($items[0]->fragile);
        $this->assertFalse($items[1]->fragile);

        $packages = $shipment->packages;
        $this->assertTrue($packages[0]['fragile']);
        $this->assertFalse($packages[1]['fragile']);
    }
}
