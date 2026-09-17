<?php

namespace App\Services;

use App\Models\OtpRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Throwable;

class OtpIssueService extends BaseService
{
    private const EXPIRES_MINUTES = 5;

    public function __construct(
        private readonly OtpMailGatewayClient $otpMailGatewayClient,
        private readonly OtpVerificationService $otpVerificationService
    ) {
    }

    public function issue(string $email, ?string $purpose = null, ?array $pendingPayload = null): OtpRecord
    {
        $normalizedEmail = $this->otpVerificationService->normalizeEmail($email);
        $normalizedPurpose = $purpose ?: 'login';
        $otp = $this->generateOtp();

        $record = DB::transaction(function () use ($normalizedEmail, $normalizedPurpose, $otp, $pendingPayload): OtpRecord {
            return OtpRecord::query()->create([
                'email' => $normalizedEmail,
                'purpose' => $normalizedPurpose,
                'otp_hash' => Hash::make($otp),
                'expires_at' => now()->addMinutes(self::EXPIRES_MINUTES),
                'used_at' => null,
                'pending_payload' => $pendingPayload,
            ]);
        });

        try {
            $this->otpMailGatewayClient->sendOtp(
                $normalizedEmail,
                $otp,
                self::EXPIRES_MINUTES,
                $normalizedPurpose
            );
        } catch (Throwable $exception) {
            $record->delete();

            throw $exception;
        }

        return $record;
    }

    private function generateOtp(): string
    {
        return (string) random_int(100000, 999999);
        //return '123456';
    }
}
