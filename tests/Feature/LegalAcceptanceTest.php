<?php

namespace Tests\Feature;

use App\Models\LegalAcceptance;
use App\Models\LegalDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LegalAcceptanceTest extends TestCase
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

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'prohibited_accepted' => true,
            'prohibited_document_id' => $this->doc->id,
        ], $overrides);
    }

    public function test_missing_document_id_returns_422(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/shipments', ['prohibited_accepted' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors('prohibited_document_id');
    }

    public function test_inactive_document_returns_422(): void
    {
        $inactive = LegalDocument::create([
            'type' => 'prohibited_items',
            'version' => 'v0.9-old',
            'title' => 'Artículos prohibidos',
            'content' => "Old list",
            'content_hash' => hash('sha256', "Old list"),
            'published_at' => now()->subDay(),
            'is_active' => false,
        ]);

        Sanctum::actingAs($this->user);

        $this->postJson('/api/shipments', $this->payload(['prohibited_document_id' => $inactive->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('prohibited_document_id');

        $this->assertDatabaseCount('legal_acceptances', 0);
    }

    public function test_valid_acceptance_creates_shipment_and_acceptance(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/shipments', $this->payload([
            'origin' => 'Santo Domingo',
            'destination' => 'Santiago',
            'user_agent_header' => null,
        ]), ['User-Agent' => 'LegalAcceptanceTest/1.0']);

        $response->assertStatus(201);

        $shipmentId = $response->json('id');
        $acceptance = LegalAcceptance::where('shipment_id', $shipmentId)->sole();

        $this->assertSame($this->user->id, $acceptance->user_id);
        $this->assertSame($this->doc->id, $acceptance->legal_document_id);
        $this->assertSame($this->doc->content_hash, $acceptance->content_hash);
        $this->assertNotNull($acceptance->accepted_at);
        $this->assertNotNull($acceptance->ip);
        $this->assertStringContainsString('LegalAcceptanceTest', (string) $acceptance->user_agent);
        $this->assertSame($this->doc->version, $response->json('prohibited_list_version'));
    }

    public function test_acceptance_is_immutable(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/shipments', $this->payload());
        $response->assertStatus(201);

        $acceptance = LegalAcceptance::firstOrFail();
        $originalHash = $acceptance->content_hash;
        $originalIp = $acceptance->ip;

        $acceptance->update(['content_hash' => 'tampered', 'ip' => '1.2.3.4']);
        $acceptance->refresh();
        $this->assertSame($originalHash, $acceptance->content_hash);
        $this->assertSame($originalIp, $acceptance->ip);

        $acceptance->delete();
        $this->assertDatabaseHas('legal_acceptances', ['id' => $acceptance->id]);
    }
}
