<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class GatewayMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $htmlBody,
        public readonly string $textBody,
        private readonly string $subjectLine,
        public readonly ?string $replyToAddress = null,
        public readonly array $fileAttachments = [],
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectLine,
            replyTo: $this->replyToAddress ? [$this->replyToAddress] : [],
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: $this->htmlBody,
            text: 'emails.gateway-text',
        );
    }

    public function attachments(): array
    {
        return array_map(function (array $file): Attachment {
            return Attachment::fromData(
                fn (): string => (string) base64_decode((string) ($file['content'] ?? ''), true),
                (string) ($file['name'] ?? 'attachment')
            )->withMime((string) ($file['mime'] ?? 'application/octet-stream'));
        }, $this->fileAttachments);
    }
}
