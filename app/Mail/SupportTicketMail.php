<?php

namespace App\Mail;

use App\Models\SupportTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SupportTicketMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public SupportTicket $ticket)
    {
        //
    }

    public function envelope(): Envelope
    {
        $fromAddress = config('mail.from.address', 'uraharazamora@gmail.com');
        $fromName = config('mail.from.name', 'TR3SLOG');

        return new Envelope(
            from: new Address($fromAddress, $fromName),
            replyTo: [new Address($this->ticket->user->email, $this->ticket->user->name ?? '')],
            subject: 'Caso de soporte recibido - #' . $this->ticket->id,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.support-ticket',
            with: [
                'ticket' => $this->ticket,
                'user' => $this->ticket->user,
            ],
        );
    }
}
