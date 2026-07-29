<?php

namespace App\Http\Controllers;

use App\Http\Requests\SendOtpMailRequest;
use App\Services\OtpMailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class InternalMailController extends Controller
{
    public function sendOtp(SendOtpMailRequest $request, OtpMailService $otpMailService): JsonResponse
    {
        $validated = $request->validated();

        try {
            $otpMailService->send(
                $validated['email'],
                $validated['otp'],
                (int) $validated['expires_minutes'],
                $validated['purpose'],
            );

            return response()->json([
                'success' => true,
                'message' => 'OTP email sent successfully.',
            ]);
        } catch (Throwable $exception) {
            Log::warning('OTP email gateway send failed.', [
                'email' => $otpMailService->maskEmail($validated['email']),
                'purpose' => $validated['purpose'],
                'success' => false,
                'exception' => $exception::class,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to send OTP email at this time.',
            ], 502);
        }
    }
}
