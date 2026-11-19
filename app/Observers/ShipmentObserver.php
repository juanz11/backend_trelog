<?php

namespace App\Observers;

use App\Models\Shipment;
use App\Services\ShipmentNotificationService;

class ShipmentObserver
{
    /**
     * Shipment status → notification event.
     */
    private const STATUS_EVENTS = [
        'assigned' => 'driver_assigned',
        'picked_up' => 'picked_up',
        'recogido' => 'picked_up',
        'in_transit' => 'in_transit',
        'incident' => 'failed_attempt',
        'incidencia' => 'failed_attempt',
        'failed_attempt' => 'failed_attempt',
        'delivered' => 'delivered',
        'entregado' => 'delivered',
        'entregada' => 'delivered',
        'returned' => 'returned',
        'devuelto' => 'returned',
    ];

    /**
     * Statuses that trigger the recobro (collection) request email.
     */
    private const RECOBRO_STATUSES = ['returned', 'devuelto', 'cancelled', 'cancelado'];

    public function created(Shipment $shipment): void
    {
        $this->logStatus($shipment);
        app(ShipmentNotificationService::class)->notify($shipment, 'confirmed');
    }

    public function updated(Shipment $shipment): void
    {
        if (! $shipment->wasChanged('status')) {
            return;
        }

        $this->logStatus($shipment);

        $status = (string) $shipment->status;
        $notifications = app(ShipmentNotificationService::class);

        if (isset(self::STATUS_EVENTS[$status])) {
            $notifications->notify($shipment, self::STATUS_EVENTS[$status]);
        }

        if ($status === 'assigned' && $shipment->driver_id) {
            $notifications->notifyDriver($shipment);
        }

        if (in_array($status, self::RECOBRO_STATUSES, true)) {
            $notifications->notify($shipment, 'recobro_requested');
        }
    }

    /**
     * Append the current status to the shipment's status history so every
     * step of the client timeline has a real timestamp.
     */
    private function logStatus(Shipment $shipment): void
    {
        $history = $shipment->status_history ?? [];
        $history[] = [
            'status' => (string) ($shipment->status ?? 'pending'),
            'at' => now()->toISOString(),
        ];

        $shipment->status_history = $history;
        $shipment->saveQuietly();
    }
}
