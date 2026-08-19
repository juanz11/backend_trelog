<?php

namespace App\Mail;

use App\Models\SupportTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
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
        return new Envelope(
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
