<?php

namespace App\Services;

use App\Mail\ShipmentStatusMail;
use App\Models\Shipment;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends the client-facing shipment status emails. Failures are logged and
 * swallowed so a mail outage never breaks an operational status change.
 */
class ShipmentNotificationService
{
    public function notify(Shipment $shipment, string $event): void
    {
        $email = $shipment->client?->email
            ?: $shipment->sender_email
            ?: $shipment->recipient_email;

        if (! $email) {
            return;
        }

        try {
            Mail::to($email)->send(new ShipmentStatusMail($shipment, $event));
        } catch (\Throwable $e) {
            Log::warning("No se pudo enviar el correo '{$event}' del envío {$shipment->tracking_number}: {$e->getMessage()}");
        }
    }
}
