<?php

namespace App\Mail;

use App\Models\Quote;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class QuoteCreatedMail extends Mailable
{
    use Queueable, SerializesModels;

    public Quote $quote;

    public function __construct(Quote $quote)
    {
        $this->quote = $quote;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Tu código de cotización — TR3SLOG',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.quote-created',
            with: ['quote' => $this->quote],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
