<?php

namespace App\Mail;

use App\Models\ContactQuery;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContactQueryThanksMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly ContactQuery $contactQuery)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Thank you for contacting Your Good Time',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.contact-query-thanks',
            text: 'emails.contact-query-thanks-text',
        );
    }
}
