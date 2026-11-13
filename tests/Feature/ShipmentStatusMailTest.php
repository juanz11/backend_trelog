<?php

namespace Tests\Feature;

use App\Mail\ShipmentStatusMail;
use App\Models\DeliveryRoute;
use App\Models\Role;
use App\Models\RouteStop;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShipmentStatusMailTest extends TestCase
{
    use RefreshDatabase;

    private function makeShipment(array $attrs = []): Shipment
    {
        return Shipment::create(array_merge([
            'user_id' => User::factory()->create()->id,
            'origin' => 'Santo Domingo, RD',
            'destination' => 'Santiago, RD',
            'status' => 'pending',
            'amount' => 100,
        ], $attrs));
    }

    private function assertEventSent(string $event): void
    {
        Mail::assertSent(ShipmentStatusMail::class, fn (ShipmentStatusMail $mail) => $mail->event === $event);
    }

    public function test_confirmed_email_is_sent_on_creation(): void
    {
        Mail::fake();

        $this->makeShipment();

        $this->assertEventSent('confirmed');
    }

    public function test_status_changes_send_their_emails(): void
    {
        Mail::fake();
        $shipment = $this->makeShipment();
        Mail::fake(); // reset after creation email

        $shipment->update(['status' => 'assigned']);
        $this->assertEventSent('driver_assigned');

        $shipment->update(['status' => 'in_transit']);
        $this->assertEventSent('in_transit');

        $shipment->update(['status' => 'failed_attempt']);
        $this->assertEventSent('failed_attempt');

        $shipment->update(['status' => 'delivered']);
        $this->assertEventSent('delivered');
    }

    public function test_returned_sends_returned_and_recobro_emails(): void
    {
        Mail::fake();
        $shipment = $this->makeShipment();
        Mail::fake();

        $shipment->update(['status' => 'returned']);

        $this->assertEventSent('returned');
        $this->assertEventSent('recobro_requested');
    }

    public function test_cancelled_sends_recobro_email(): void
    {
        Mail::fake();
        $shipment = $this->makeShipment();
        Mail::fake();

        $shipment->update(['status' => 'cancelled']);

        $this->assertEventSent('recobro_requested');
        Mail::assertNotSent(ShipmentStatusMail::class, fn (ShipmentStatusMail $mail) => $mail->event === 'returned');
    }

    public function test_pickup_stop_confirmation_sends_picked_up_email(): void
    {
        Mail::fake();

        Role::firstOrCreate(['name' => 'driver'], ['display_name' => 'driver']);
        $driver = User::factory()->create();
        $driver->assignRole('driver');

        $shipment = $this->makeShipment(['status' => 'assigned']);
        $route = DeliveryRoute::create([
            'driver_id' => $driver->id,
            'code' => 'RT-TEST-01',
            'date_label' => 'Test',
            'status' => 'Pending',
        ]);
        $stop = RouteStop::create([
            'route_id' => $route->id,
            'shipment_id' => $shipment->id,
            'n' => 1,
            'name' => 'Origen',
            'addr' => 'Santo Domingo, RD',
            'type' => 'Pickup',
            'state' => 'Next',
        ]);
        Mail::fake(); // reset creation/assignment emails

        Sanctum::actingAs($driver);
        $this->postJson("/api/driver/stops/{$stop->id}/confirm")->assertOk();

        $this->assertEventSent('picked_up');
        $this->assertEventSent('in_transit');
    }

    public function test_cancelled_shipment_email_has_no_tracking_link(): void
    {
        $shipment = $this->makeShipment(['status' => 'cancelled']);

        $html = (new ShipmentStatusMail($shipment->fresh(), 'recobro_requested'))->render();

        $this->assertStringNotContainsString('Rastrear envío', $html);
        $this->assertStringNotContainsString($shipment->getTrackingUrl(), $html);
    }

    public function test_active_shipment_email_keeps_tracking_link(): void
    {
        $shipment = $this->makeShipment(['status' => 'in_transit']);

        $html = (new ShipmentStatusMail($shipment->fresh(), 'in_transit'))->render();

        $this->assertStringContainsString('Rastrear envío', $html);
    }

    public function test_delivery_stop_does_not_send_picked_up(): void
    {
        Mail::fake();

        Role::firstOrCreate(['name' => 'driver'], ['display_name' => 'driver']);
        $driver = User::factory()->create();
        $driver->assignRole('driver');

        $shipment = $this->makeShipment(['status' => 'out_for_delivery']);
        $route = DeliveryRoute::create([
            'driver_id' => $driver->id,
            'code' => 'RT-TEST-02',
            'date_label' => 'Test',
            'status' => 'Pending',
        ]);
        $stop = RouteStop::create([
            'route_id' => $route->id,
            'shipment_id' => $shipment->id,
            'n' => 1,
            'name' => 'Destino',
            'addr' => 'Santiago, RD',
            'type' => 'Delivery',
            'state' => 'Next',
        ]);
        Mail::fake();

        Sanctum::actingAs($driver);
        $this->postJson("/api/driver/stops/{$stop->id}/confirm")->assertOk();

        $this->assertEventSent('delivered');
        Mail::assertNotSent(ShipmentStatusMail::class, fn (ShipmentStatusMail $mail) => $mail->event === 'picked_up');
    }
}
