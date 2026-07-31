<?php

namespace App\Services;

use App\Exceptions\MailGatewayException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class OtpMailGatewayClient extends BaseService
{
    public function __construct(
        private readonly OtpMailService $otpMailService
    ) {
    }

    public function sendOtp(string $email, string $otp, int $expiresMinutes, string $purpose): void
    {
        $url = (string) config('services.mail_gateway.url', '');
        $key = (string) config('services.mail_gateway.key', '');
        $timeout = (int) config('services.mail_gateway.timeout', 10);

        $payload = [
            'email' => $email,
            'otp' => $otp,
            'expires_minutes' => $expiresMinutes,
            'purpose' => $purpose,
        ];

        Log::info('Sending OTP through production mail gateway.', [
            'url' => $url,
            'email' => $this->otpMailService->maskEmail($email),
        ]);

        if (app()->environment('local') && (bool) config('services.mail_gateway.use_local_mailer', true)) {
            Log::info('Using local mailer for OTP delivery.', [
                'email' => $this->otpMailService->maskEmail($email),
                'purpose' => $purpose,
            ]);

            $this->otpMailService->send($email, $otp, $expiresMinutes, $purpose);

            return;
        }

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

            Log::info('Production mail gateway OTP response received.', [
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
            Log::error('Production mail gateway OTP request failed.', [
                'url' => $url,
                'email' => $this->otpMailService->maskEmail($email),
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
            ]);

            throw new MailGatewayException('Unable to connect to production mail gateway.', null, null, previous: $exception);
        } catch (MailGatewayException $exception) {
            Log::warning('Production mail gateway OTP request was unsuccessful.', [
                'url' => $url,
                'email' => $this->otpMailService->maskEmail($email),
                'status' => $exception->responseStatus(),
                'body' => $exception->responseBody(),
                'exception_message' => $exception->getMessage(),
            ]);

            throw $exception;
        } catch (Throwable $exception) {
            Log::error('Unexpected production mail gateway OTP failure.', [
                'url' => $url,
                'email' => $this->otpMailService->maskEmail($email),
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
            ]);

            throw new MailGatewayException('Unexpected production mail gateway failure.', null, null, previous: $exception);
        }
    }
}
