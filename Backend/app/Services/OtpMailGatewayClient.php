<?php

namespace App\Services;

use App\Exceptions\MailGatewayException;
use App\Mail\GatewayMail;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

class OtpMailGatewayClient extends BaseService
{
    public function __construct(
        private readonly OtpMailService $otpMailService,
        private readonly ZeptoMailOtpService $zeptoMailOtpService
    ) {
    }

    public function sendOtp(string $email, string $otp, int $expiresMinutes, string $purpose): void
    {
        $system = strtolower(trim((string) config('services.otp_mail.system', 'local')));

        if ($system === 'local') {
            Log::info('Using local mailer for OTP delivery.', [
                'email' => $this->otpMailService->maskEmail($email),
                'purpose' => $purpose,
            ]);

            $this->otpMailService->send($email, $otp, $expiresMinutes, $purpose);

            return;
        }

        if ($system === 'zeptoapi') {
            Log::info('Using ZeptoMail API for OTP delivery.', [
                'email' => $this->otpMailService->maskEmail($email),
                'purpose' => $purpose,
            ]);

            $this->zeptoMailOtpService->send($email, $otp, $expiresMinutes, $purpose);

            return;
        }

        Log::error('Unsupported OTP mail system configured.', [
            'system' => $system,
            'email' => $this->otpMailService->maskEmail($email),
            'purpose' => $purpose,
        ]);

        throw new RuntimeException('Unsupported OTP mail system: '.$system);
    }

    public function sendMail(
        string $to,
        string $subject,
        string $html,
        string $text,
        ?string $replyTo = null,
        array $attachments = [],
    ): void {
        $payload = array_filter([
            'to' => $to,
            'subject' => $subject,
            'html' => $html,
            'text' => $text,
            'reply_to' => $replyTo,
            'attachments' => $attachments === [] ? null : $attachments,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        Log::info('Sending mail through production mail gateway.', [
            'url' => $this->sendUrl(),
            'email' => $this->otpMailService->maskEmail($to),
        ]);

        if ($this->shouldUseLocalMailer()) {
            Log::info('Using local mailer for transactional delivery.', [
                'email' => $this->otpMailService->maskEmail($to),
            ]);

            Mail::to($to)->send(new GatewayMail(
                htmlBody: $html,
                textBody: $text,
                subjectLine: $subject,
                replyToAddress: $replyTo,
                fileAttachments: $attachments,
            ));

            return;
        }

        $this->postToGateway($this->sendUrl(), $payload, $to, 'mail');
    }

    public function shouldUseLocalMailer(): bool
    {
        return app()->environment('local') && (bool) config('services.mail_gateway.use_local_mailer', true);
    }

    private function otpUrl(): string
    {
        return (string) config('services.mail_gateway.url', '');
    }

    private function sendUrl(): string
    {
        $explicit = trim((string) config('services.mail_gateway.send_url', ''));

        if ($explicit !== '') {
            return $explicit;
        }

        $otpUrl = $this->otpUrl();

        return str_ends_with($otpUrl, '/send-otp')
            ? substr($otpUrl, 0, -strlen('/send-otp')).'/send'
            : rtrim($otpUrl, '/').'/send';
    }

    private function postToGateway(string $url, array $payload, string $email, string $kind): void
    {
        $key = (string) config('services.mail_gateway.key', '');
        $timeout = (int) config('services.mail_gateway.timeout', 10);

        if ($url === '') {
            throw new MailGatewayException('Mail gateway URL is not configured.');
        }

        if ($key === '') {
            throw new MailGatewayException('Mail gateway key is not configured.');
        }

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->asJson()
                ->withHeaders([
                    'X-Mail-Gateway-Key' => $key,
                ])
                ->post($url, $payload);

            Log::info('Production mail gateway '.$kind.' response received.', [
                'url' => $url,
                'email' => $this->otpMailService->maskEmail($email),
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            if (! $response->successful()) {
                throw new MailGatewayException(
                    'Production mail gateway returned an unsuccessful response.',
                    $response->status(),
                    $response->body()
                );
            }
        } catch (ConnectionException $exception) {
            Log::error('Production mail gateway '.$kind.' request failed.', [
                'url' => $url,
                'email' => $this->otpMailService->maskEmail($email),
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
            ]);

            throw new MailGatewayException('Unable to connect to production mail gateway.', null, null, previous: $exception);
        } catch (MailGatewayException $exception) {
            Log::warning('Production mail gateway '.$kind.' request was unsuccessful.', [
                'url' => $url,
                'email' => $this->otpMailService->maskEmail($email),
                'status' => $exception->responseStatus(),
                'body' => $exception->responseBody(),
                'exception_message' => $exception->getMessage(),
            ]);

            throw $exception;
        } catch (Throwable $exception) {
            Log::error('Unexpected production mail gateway '.$kind.' failure.', [
                'url' => $url,
                'email' => $this->otpMailService->maskEmail($email),
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
            ]);

            throw new MailGatewayException('Unexpected production mail gateway failure.', null, null, previous: $exception);
        }
    }
}
