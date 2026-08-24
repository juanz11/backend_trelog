<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContactSupportMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public array $data)
    {
        //
    }

    public function envelope(): Envelope
    {
        $fromAddress = config('mail.from.address', 'uraharazamora@gmail.com');
        $fromName = config('mail.from.name', 'TR3SLOG');

        return new Envelope(
            from: new Address($fromAddress, $fromName),
            replyTo: [new Address($this->data['email'], $this->data['name'])],
            subject: 'Contacto web ' . ($this->data['reference'] ?? '') . ': ' . $this->data['subject'],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.contact-support',
            with: [
                'data' => $this->data,
            ],
        );
    }
}
