<?php

namespace App\Mail;

use App\Models\Shipment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ShipmentStatusMail extends Mailable
{
    use Queueable, SerializesModels;

    public const EVENTS = [
        'confirmed' => 'Envío confirmado',
        'driver_assigned' => 'Conductor asignado',
        'picked_up' => 'Paquete recogido',
        'in_transit' => 'Envío en tránsito',
        'failed_attempt' => 'Intento de entrega fallido',
        'delivered' => 'Envío entregado',
        'returned' => 'Envío devuelto',
        'recobro_requested' => 'Solicitud de recobro',
    ];

    public function __construct(
        public Shipment $shipment,
        public string $event,
    ) {
    }

    public function envelope(): Envelope
    {
        $label = self::EVENTS[$this->event] ?? 'Actualización de envío';

        return new Envelope(
            subject: "{$label} — {$this->shipment->tracking_number}",
        );
    }

    public function content(): Content
    {
        $shipment = $this->shipment->loadMissing(['client', 'driver']);
        $label = self::EVENTS[$this->event] ?? 'Actualización de envío';

        return new Content(
            view: 'emails.shipment-status',
            with: [
                'shipment' => $shipment,
                'eventKey' => $this->event,
                'eventLabel' => $label,
                'clientName' => $shipment->client?->name ?: $shipment->sender_name ?: 'cliente',
                'body' => $this->body($shipment),
                'trackingUrl' => $shipment->getTrackingUrl(),
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }

    private function body(Shipment $shipment): string
    {
        $tracking = (string) $shipment->tracking_number;
        $destination = $shipment->destination ?: 'su destino';

        $text = match ($this->event) {
            'confirmed' => "Tu envío {$tracking} fue confirmado. Te avisaremos en cada paso del recorrido.",
            'driver_assigned' => "Asignamos un conductor a tu envío {$tracking}.",
            'picked_up' => "El conductor recogió tu paquete en el origen. El envío {$tracking} ya va en camino.",
            'in_transit' => "Tu envío {$tracking} está en tránsito hacia {$destination}.",
            'failed_attempt' => "No pudimos entregar el envío {$tracking}. Reintentaremos la entrega.",
            'delivered' => "Tu envío {$tracking} fue entregado. Gracias por confiar en TR3SLOG.",
            'returned' => "Tu envío {$tracking} fue devuelto al origen.",
            'recobro_requested' => "Tu envío {$tracking} tiene un cargo pendiente de cobro.",
            default => "Tu envío {$tracking} tiene una actualización.",
        };

        if ($this->event === 'recobro_requested' && is_numeric($shipment->cancel_fee) && (float) $shipment->cancel_fee > 0) {
            $text .= ' El monto a cobrar es de $' . number_format((float) $shipment->cancel_fee, 2) . '.';
        }

        return $text;
    }
}
