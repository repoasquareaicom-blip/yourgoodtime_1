<?php

namespace App\Services;

use App\Mail\OtpMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class OtpMailService extends BaseService
{
    public function send(string $email, string $otp, int $expiresMinutes, string $purpose): void
    {
        try {
            $content = $this->contentForPurpose($purpose);

            Mail::to($email)->send(new OtpMail(
                otp: $otp,
                expiresMinutes: $expiresMinutes,
                purpose: $purpose,
                subjectLine: $content['subject'],
                messageText: $content['message'],
            ));

            Log::info('OTP email sent.', [
                'email' => $this->maskEmail($email),
                'purpose' => $purpose,
                'success' => true,
            ]);
        } catch (Throwable $exception) {
            Log::error('OTP email gateway send failed.', [
                'email' => $this->maskEmail($email),
                'purpose' => $purpose,
                'success' => false,
                'exception_class' => get_class($exception),
                'exception_message' => $exception->getMessage(),
                'exception_code' => $exception->getCode(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ]);

            throw $exception;
        }
    }

    public function contentForPurpose(string $purpose): array
    {
        return match ($purpose) {
            'signup' => [
                'subject' => 'Verify Your Account - Your Good Time',
                'message' => 'Use this OTP to verify your account and continue your Your Good Time journey.',
            ],
            'email_verification' => [
                'subject' => 'Verify Your Email - Your Good Time',
                'message' => 'Use this OTP to verify your email address.',
            ],
            'password_reset' => [
                'subject' => 'Reset Your Password - Your Good Time',
                'message' => 'Use this OTP to continue resetting your password.',
            ],
            default => [
                'subject' => 'Your Login OTP - Your Good Time',
                'message' => 'Use this OTP to securely log in to Your Good Time.',
            ],
        };
    }

    public function maskEmail(string $email): string
    {
        [$localPart, $domain] = array_pad(explode('@', $email, 2), 2, '');

        $visible = mb_substr($localPart, 0, 2);

        return $domain === ''
            ? 'invalid-email'
            : $visible.'***@'.$domain;
    }
}
