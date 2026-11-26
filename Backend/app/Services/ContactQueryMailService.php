<?php

namespace App\Services;

use App\Mail\ContactQuerySupportMail;
use App\Mail\ContactQueryThanksMail;
use App\Models\ContactQuery;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ContactQueryMailService extends BaseService
{
    public function __construct(
        private readonly OtpMailGatewayClient $mailGateway
    ) {
    }

    public function send(ContactQuery $query): void
    {
        $this->sendThanks($query);
        $this->sendSupport($query);
    }

    public function sendThanks(ContactQuery $query): void
    {
        try {
            if ($this->shouldSendDirectly()) {
                Mail::to($query->email)->send(new ContactQueryThanksMail($query));

                return;
            }

            $this->mailGateway->sendMail(
                to: $query->email,
                subject: 'Thank you for contacting Your Good Time',
                html: view('emails.contact-query-thanks', ['contactQuery' => $query])->render(),
                text: view('emails.contact-query-thanks-text', ['contactQuery' => $query])->render(),
            );
        } catch (Throwable $exception) {
            Log::error('Contact query thanks email failed.', $this->logContext($query, $exception));
            throw $exception;
        }
    }

    public function sendSupport(ContactQuery $query): void
    {
        $supportAddress = (string) config('mail.support.address');

        try {
            if ($this->shouldSendDirectly()) {
                Mail::to($supportAddress)->send(new ContactQuerySupportMail($query));

                return;
            }

            $this->mailGateway->sendMail(
                to: $supportAddress,
                subject: 'New website query from '.$query->email,
                html: view('emails.contact-query-support', ['contactQuery' => $query])->render(),
                text: view('emails.contact-query-support-text', ['contactQuery' => $query])->render(),
                replyTo: $query->email,
                attachments: $this->attachmentPayload($query),
            );
        } catch (Throwable $exception) {
            Log::error('Contact query support email failed.', $this->logContext($query, $exception));
            throw $exception;
        }
    }

    private function shouldSendDirectly(): bool
    {
        return ! app()->environment('local') || $this->mailGateway->shouldUseLocalMailer();
    }

    private function attachmentPayload(ContactQuery $query): array
    {
        if (! $query->hasAttachment() || ! Storage::disk('local')->exists($query->attachment_path)) {
            return [];
        }

        return [[
            'name' => $query->attachment_original_name ?: 'attachment',
            'mime' => $query->attachment_mime_type ?: 'application/octet-stream',
            'content' => base64_encode((string) Storage::disk('local')->get($query->attachment_path)),
        ]];
    }

    private function logContext(ContactQuery $query, Throwable $exception): array
    {
        return [
            'query_id' => $query->id,
            'email' => $query->email,
            'exception_class' => get_class($exception),
            'exception_message' => $exception->getMessage(),
        ];
    }
}
