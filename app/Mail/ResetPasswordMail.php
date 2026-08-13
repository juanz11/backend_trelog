<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ResetPasswordMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $email;
    public string $token;
    public string $resetUrl;

    public function __construct(string $email, string $token)
    {
        $this->email = $email;
        $this->token = $token;
        $this->resetUrl = 'http://localhost:3000/reset-password?token=' . $token . '&email=' . urlencode($email);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Restablecer contraseña · TR3SLOG',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.reset',
            with: [
                'email' => $this->email,
                'token' => $this->token,
                'resetUrl' => $this->resetUrl,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
