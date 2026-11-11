<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\PricingConfig;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShipmentCancellationFeeTest extends TestCase
{
    use RefreshDatabase;

    private User $ops;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'shipments.edit'], [
            'display_name' => 'shipments.edit', 'description' => 'shipments.edit', 'module' => 'shipments',
        ]);
        Permission::firstOrCreate(['name' => 'pricing.edit'], [
            'display_name' => 'pricing.edit', 'description' => 'pricing.edit', 'module' => 'pricing',
        ]);

        $this->ops = User::factory()->create();
        $this->ops->givePermissionTo(['shipments.edit', 'pricing.edit']);
    }

    private function makeShipment(array $attrs = []): Shipment
    {
        $createdAt = $attrs['created_at'] ?? null;
        unset($attrs['created_at']);

        $shipment = Shipment::create(array_merge([
            'user_id' => User::factory()->create()->id,
            'origin' => 'Santo Domingo, RD',
            'destination' => 'Santiago, RD',
            'status' => 'pending',
            'amount' => 100,
        ], $attrs));

        if ($createdAt) {
            $shipment->created_at = $createdAt;
            $shipment->save();
        }

        return $shipment;
    }

    public function test_cancel_within_window_charges_configured_percent(): void
    {
        Sanctum::actingAs($this->ops);
        $shipment = $this->makeShipment(['created_at' => now()->subHours(10)]);

        $this->putJson("/api/shipments/{$shipment->id}", ['status' => 'cancelled'])->assertOk();

        $shipment->refresh();
        $this->assertSame('cancelled', $shipment->status);
        $this->assertSame('5.00', $shipment->cancel_fee_percent);
        $this->assertSame('5.00', $shipment->cancel_fee);
        $this->assertNotNull($shipment->cancelled_at);
    }

    public function test_cancel_after_window_charges_recobro_percent(): void
    {
        Sanctum::actingAs($this->ops);
        $shipment = $this->makeShipment(['created_at' => now()->subHours(100)]);

        $this->putJson("/api/shipments/{$shipment->id}", ['status' => 'cancelled'])->assertOk();

        $shipment->refresh();
        $this->assertSame('30.00', $shipment->cancel_fee_percent);
        $this->assertSame('30.00', $shipment->cancel_fee);
    }

    public function test_tolerance_extends_the_window(): void
    {
        Sanctum::actingAs($this->ops);
        PricingConfig::current()->update([
            'policies' => ['cancellation' => ['tolerance_minutes' => 120]],
        ]);

        // 73h elapsed: past the 72h window but inside window + tolerance.
        $shipment = $this->makeShipment(['created_at' => now()->subHours(73)]);

        $this->putJson("/api/shipments/{$shipment->id}", ['status' => 'cancelled'])->assertOk();

        $shipment->refresh();
        $this->assertSame('5.00', $shipment->cancel_fee_percent);
    }

    public function test_fee_uses_admin_configured_values_not_defaults(): void
    {
        Sanctum::actingAs($this->ops);
        PricingConfig::current()->update([
            'policies' => ['cancellation' => ['percent' => 10, 'recobro_percent' => 50]],
        ]);

        $within = $this->makeShipment(['created_at' => now()->subHour()]);
        $late = $this->makeShipment(['created_at' => now()->subDays(10)]);

        $this->putJson("/api/shipments/{$within->id}", ['status' => 'cancelled'])->assertOk();
        $this->putJson("/api/shipments/{$late->id}", ['status' => 'cancelado'])->assertOk();

        $this->assertSame('10.00', $within->refresh()->cancel_fee_percent);
        $this->assertSame('10.00', $within->cancel_fee);
        $this->assertSame('50.00', $late->refresh()->cancel_fee_percent);
        $this->assertSame('50.00', $late->cancel_fee);
    }

    public function test_reactivating_a_cancelled_shipment_clears_the_fee(): void
    {
        Sanctum::actingAs($this->ops);
        $shipment = $this->makeShipment();

        $this->putJson("/api/shipments/{$shipment->id}", ['status' => 'cancelled'])->assertOk();
        $this->assertNotNull($shipment->refresh()->cancel_fee);

        $this->putJson("/api/shipments/{$shipment->id}", ['status' => 'pending'])->assertOk();

        $shipment->refresh();
        $this->assertNull($shipment->cancelled_at);
        $this->assertNull($shipment->cancel_fee);
        $this->assertNull($shipment->cancel_fee_percent);
    }

    public function test_pricing_config_accepts_and_returns_policies(): void
    {
        Sanctum::actingAs($this->ops);

        $this->putJson('/api/pricing-config', [
            'dim_divisor' => 166,
            'policies' => ['cancellation' => [
                'percent' => 7,
                'recobro_percent' => 35,
                'window_hours' => 48,
                'tolerance_minutes' => 15,
            ]],
        ])->assertOk();

        $config = PricingConfig::current();
        $this->assertEquals(7, $config->policies['cancellation']['percent']);
        $this->assertEquals(35, $config->policies['cancellation']['recobro_percent']);
        $this->assertEquals(48, $config->policies['cancellation']['window_hours']);
        $this->assertEquals(15, $config->policies['cancellation']['tolerance_minutes']);

        // Missing keys always resolve against DEFAULTS.
        $config->update(['policies' => ['cancellation' => ['percent' => 7]]]);
        $policy = $config->fresh()->cancellationPolicy();
        $this->assertSame(7.0, $policy['percent']);
        $this->assertSame(30.0, $policy['recobro_percent']);
        $this->assertSame(72.0, $policy['window_hours']);
        $this->assertSame(0.0, $policy['tolerance_minutes']);
    }
}
