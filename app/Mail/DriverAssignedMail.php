<?php

namespace App\Mail;

use App\Models\Shipment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DriverAssignedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Shipment $shipment) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'TR3SLOG · Envío asignado ' . $this->shipment->tracking_number,
        );
    }

    public function content(): Content
    {
        $s = $this->shipment;
        return new Content(
            htmlString: '<div style="font-family:sans-serif;max-width:480px;margin:auto;padding:32px;background:#f4f8fd;border-radius:16px">'
                . '<div style="font-size:22px;font-weight:800;color:#001B45;letter-spacing:1px">TR3SLOG</div>'
                . '<p style="color:#33475b;font-weight:700;font-size:16px">Se te asignó un envío</p>'
                . '<div style="background:#fff;border-radius:12px;padding:18px;color:#33475b">'
                . '<b>Guía:</b> ' . e($s->tracking_number) . '<br>'
                . '<b>Ruta:</b> ' . e($s->origin) . ' → ' . e($s->destination) . '<br>'
                . '<b>Destinatario:</b> ' . e($s->recipient_name ?? '—') . '<br>'
                . '<b>Teléfono:</b> ' . e($s->recipient_phone ?? '—') . '<br>'
                . '<b>Dirección:</b> ' . e($s->recipient_address ?? '—')
                . '</div>'
                . '<p style="color:#8B9DBA;font-size:12px">Revisa los detalles en tu aplicación de conductor.</p>'
                . '</div>',
        );
    }
}
