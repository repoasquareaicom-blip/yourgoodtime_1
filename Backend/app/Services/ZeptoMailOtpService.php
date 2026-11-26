<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ZeptoMailOtpService extends BaseService
{
    public function __construct(
        private readonly OtpMailService $otpMailService
    ) {
    }

    public function send(string $email, string $otp, int $expiresMinutes, string $purpose): void
    {
        $content = $this->otpMailService->contentForPurpose($purpose);
        $url = trim((string) config('services.zeptomail_api.url', 'https://api.zeptomail.in/v1.1/email'));

        $html = view('emails.otp', [
            'otp' => $otp,
            'expiresMinutes' => $expiresMinutes,
            'purpose' => $purpose,
            'messageText' => $content['message'],
        ])->render();

        $text = view('emails.otp-text', [
            'otp' => $otp,
            'expiresMinutes' => $expiresMinutes,
            'purpose' => $purpose,
            'messageText' => $content['message'],
        ])->render();

        $payload = [
            'from' => [
                'address' => (string) config('services.zeptomail_api.from_address', 'support@yourgoodtime.in'),
                'name' => (string) config('services.zeptomail_api.from_name', 'Your Good Time'),
            ],
            'to' => [
                [
                    'email_address' => [
                        'address' => $email,
                    ],
                ],
            ],
            'subject' => $content['subject'],
            'htmlbody' => $html,
            'textbody' => $text,
        ];

        try {
            if ($url === '') {
                $this->logFailure($email, $purpose, null, new RuntimeException('ZeptoMail API URL is not configured.'));

                throw new RuntimeException('Unable to send OTP email at this time.');
            }

            $response = Http::connectTimeout((int) config('services.zeptomail_api.connect_timeout', 5))
                ->timeout((int) config('services.zeptomail_api.timeout', 10))
                ->acceptJson()
                ->asJson()
                ->withHeaders([
                    'Authorization' => $this->authorizationHeader(),
                ])
                ->post($url, $payload);

            if ($response->successful()) {
                Log::info('OTP email sent via ZeptoMail API.', [
                    'email' => $this->otpMailService->maskEmail($email),
                    'purpose' => $purpose,
                    'status' => $response->status(),
                    'success' => true,
                ]);

                return;
            }

            $this->logFailure($email, $purpose, $response, null);

            throw new RuntimeException('Unable to send OTP email at this time.');
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() !== 'Unable to send OTP email at this time.') {
                $this->logFailure($email, $purpose, null, $exception);

                throw new RuntimeException('Unable to send OTP email at this time.', 0, $exception);
            }

            throw $exception;
        } catch (ConnectionException $exception) {
            $this->logFailure($email, $purpose, null, $exception);

            throw new RuntimeException('Unable to send OTP email at this time.', 0, $exception);
        } catch (Throwable $exception) {
            $this->logFailure($email, $purpose, null, $exception);

            throw new RuntimeException('Unable to send OTP email at this time.', 0, $exception);
        }
    }

    private function authorizationHeader(): string
    {
        $token = trim((string) config('services.zeptomail_api.token', ''));

        if ($token === '') {
            throw new RuntimeException('ZeptoMail API token is not configured.');
        }

        if (str_starts_with(mb_strtolower($token), 'zoho-enczapikey ')) {
            return $token;
        }

        return 'Zoho-enczapikey '.$token;
    }

    private function logFailure(string $email, string $purpose, ?Response $response, ?Throwable $exception): void
    {
        $context = [
            'email' => $this->otpMailService->maskEmail($email),
            'purpose' => $purpose,
            'success' => false,
        ];

        if ($response !== null) {
            $context['status'] = $response->status();
            $safeError = $this->safeErrorFromResponse($response);

            if ($safeError['code'] !== null) {
                $context['zeptomail_error_code'] = $safeError['code'];
            }

            if ($safeError['message'] !== null) {
                $context['zeptomail_error_message'] = $safeError['message'];
            }
        }

        if ($exception !== null) {
            $context['exception_class'] = $exception::class;
            $context['exception_message'] = $exception->getMessage();
        }

        Log::error('OTP ZeptoMail API send failed.', $context);
    }

    private function safeErrorFromResponse(Response $response): array
    {
        $json = $response->json();

        if (! is_array($json)) {
            return ['code' => null, 'message' => null];
        }

        $error = $json['error'] ?? null;

        if (! is_array($error) && isset($json['data'][0]) && is_array($json['data'][0])) {
            $error = $json['data'][0];
        }

        if (! is_array($error)) {
            $error = $json;
        }

        $code = $error['code'] ?? null;
        $message = $error['message'] ?? $error['more_info'] ?? null;

        if (! is_string($message) && is_string($json['message'] ?? null)) {
            $message = $json['message'];
        }

        return [
            'code' => is_string($code) || is_int($code) ? (string) $code : null,
            'message' => is_string($message) ? $message : null,
        ];
    }
}
