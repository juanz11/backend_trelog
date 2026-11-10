<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VerificationCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $code) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'TR3SLOG · Código de verificación',
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: '<div style="font-family:sans-serif;max-width:420px;margin:auto;padding:32px;background:#f4f8fd;border-radius:16px">'
                . '<div style="font-size:22px;font-weight:800;color:#001B45;letter-spacing:1px">TR3SLOG</div>'
                . '<p style="color:#33475b">Tu código de verificación de correo es:</p>'
                . '<div style="font-size:32px;font-weight:800;letter-spacing:8px;color:#087CF0;background:#fff;border-radius:12px;padding:16px;text-align:center">' . e($this->code) . '</div>'
                . '<p style="color:#8B9DBA;font-size:12px">Si no creaste esta cuenta, ignora este correo.</p>'
                . '</div>',
        );
    }
}
