<?php

namespace App\Mail;

use App\Models\ContactQuery;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContactQuerySupportMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly ContactQuery $contactQuery)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New website query from '.$this->contactQuery->email,
            replyTo: [$this->contactQuery->email],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.contact-query-support',
            text: 'emails.contact-query-support-text',
        );
    }

    public function attachments(): array
    {
        if (! $this->contactQuery->hasAttachment()) {
            return [];
        }

        return [
            Attachment::fromStorageDisk('local', $this->contactQuery->attachment_path)
                ->as($this->contactQuery->attachment_original_name ?: 'attachment')
                ->withMime($this->contactQuery->attachment_mime_type ?: 'application/octet-stream'),
        ];
    }
}
