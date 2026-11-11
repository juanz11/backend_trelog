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
        'in_transit' => 'in_transit',
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
        app(ShipmentNotificationService::class)->notify($shipment, 'confirmed');
    }

    public function updated(Shipment $shipment): void
    {
        if (! $shipment->wasChanged('status')) {
            return;
        }

        $status = (string) $shipment->status;
        $notifications = app(ShipmentNotificationService::class);

        if (isset(self::STATUS_EVENTS[$status])) {
            $notifications->notify($shipment, self::STATUS_EVENTS[$status]);
        }

        if (in_array($status, self::RECOBRO_STATUSES, true)) {
            $notifications->notify($shipment, 'recobro_requested');
        }
    }
}
