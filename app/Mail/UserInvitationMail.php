<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class UserInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public $userName;
    public $invitationUrl;
    public $companyName;
    public $inviterName;
    public $lang;

    /**
     * Create a new message instance.
     */
    public function __construct($userName, $invitationUrl, $companyName = 'TR3SLOG', $inviterName = null, $lang = 'es')
    {
        $this->userName = $userName;
        $this->invitationUrl = $invitationUrl;
        $this->companyName = $companyName;
        $this->inviterName = $inviterName;
        $this->lang = $lang;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $subject = $this->lang === 'en' 
            ? "You're invited to join {$this->companyName}"
            : "Estás invitado a unirte a {$this->companyName}";

        return new Envelope(
            subject: $subject,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.user-invitation',
            with: [
                'userName' => $this->userName,
                'invitationUrl' => $this->invitationUrl,
                'companyName' => $this->companyName,
                'inviterName' => $this->inviterName,
                'lang' => $this->lang,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
