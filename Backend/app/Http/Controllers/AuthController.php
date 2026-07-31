<?php

namespace App\Http\Controllers;

use App\Exceptions\MailGatewayException;
use App\Http\Requests\SendOtpRequest;
use App\Http\Requests\VerifyOtpRequest;
use App\Models\OtpRecord;
use App\Models\PredictionRequest;
use App\Models\User;
use App\Services\LoginTokenService;
use App\Services\OtpIssueService;
use App\Services\OtpVerificationService;
use App\Services\PredictionRequestProcessor;
use App\Services\RegistrationPredictionRequestService;
use App\Services\UserProfileService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class AuthController extends Controller
{
    public function sendOtp(
        SendOtpRequest $request,
        OtpIssueService $otpIssueService,
        OtpVerificationService $otpVerificationService,
        RegistrationPredictionRequestService $registrationPredictionRequestService
    ): JsonResponse
    {
        $validated = $request->validated();
        $purpose = $validated['purpose'] ?? 'login';
        $normalizedEmail = $otpVerificationService->normalizeEmail($validated['email']);
        $pendingPayload = null;
        $nextAction = $purpose === 'signup' ? 'VERIFY_REGISTRATION_OTP' : null;
        $accountState = null;

        if ($purpose === 'signup') {
            $hasPredictionPayload = $request->filled('full_name') || $request->filled('fullName') || $request->filled('date_of_birth') || $request->filled('dateOfBirth');
            $existingUser = User::query()->where('email', $normalizedEmail)->first();

            if ($existingUser !== null) {
                return $this->alreadyRegisteredResponse();
            } elseif ($registrationPredictionRequestService->emailExists($normalizedEmail)) {
                return $this->alreadyRegisteredResponse();
            } else {
                $pendingPayload = $hasPredictionPayload
                    ? $registrationPredictionRequestService->payloadFromRequest($request)
                    : [
                        'registration_only' => true,
                        'name' => trim((string) $request->input('name', '')),
                        'email' => $normalizedEmail,
                    ];
            }
        }

        try {
            $otpIssueService->issue(
                $normalizedEmail,
                $purpose,
                $pendingPayload,
            );

            $payload = [
                'success' => true,
                'code' => 'OTP_SENT',
                'message' => 'OTP sent successfully.',
            ];

            if ($nextAction !== null) {
                $payload['next_action'] = $nextAction;
            }

            if ($accountState !== null) {
                $payload['account_state'] = $accountState;
            }

            return response()->json($payload);
        } catch (MailGatewayException $exception) {
            Log::warning('Public OTP mail gateway send failed.', [
                'email' => isset($validated['email']) ? $this->maskEmail($validated['email']) : null,
                'purpose' => $purpose,
                'status' => $exception->responseStatus(),
                'body' => $exception->responseBody(),
                'exception_message' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to send OTP email through the mail gateway.',
                'gateway_status' => $exception->responseStatus(),
            ], 502);
        } catch (Throwable $exception) {
            Log::warning('Public OTP send failed.', [
                'purpose' => $purpose,
                'exception' => $exception::class,
                'exception_message' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to send OTP at this time.',
            ], 502);
        }
    }

    public function login(Request $request, LoginTokenService $loginTokenService): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:254'],
            'password' => ['required', 'string'],
        ]);

        $user = $loginTokenService->findUserByEmail($validated['email']);

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid email or password.',
            ], 422);
        }

        if (! $loginTokenService->isActive($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Your account is inactive.',
            ], 403);
        }

        $token = $loginTokenService->createSingleDeviceToken($user);

        return response()->json([
            'success' => true,
            'message' => 'Login successful.',
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => $loginTokenService->safeUser($user->refresh()),
        ]);
    }

    public function validateOtp(Request $request, OtpVerificationService $otpVerificationService): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:254'],
            'otp' => ['required', 'regex:/^\d{6}$/'],
            'purpose' => ['required', 'string', 'in:login,signup,email_verification,password_reset'],
        ]);

        if (! $otpVerificationService->validate(
            $validated['email'],
            $validated['otp'],
            $validated['purpose'],
        )) {
            return response()->json([
                'success' => false,
                'code' => 'INVALID_OTP',
                'message' => 'Invalid or expired OTP.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'code' => 'OTP_VALID',
            'message' => 'OTP verified successfully.',
        ]);
    }

    public function verifyOtp(
        VerifyOtpRequest $request,
        OtpVerificationService $otpVerificationService,
        LoginTokenService $loginTokenService,
        RegistrationPredictionRequestService $registrationPredictionRequestService,
        PredictionRequestProcessor $predictionRequestProcessor
    ): JsonResponse {
        $validated = $request->validated();

        if ($validated['purpose'] === 'signup') {
            return $this->verifySignupOtp(
                $validated,
                $otpVerificationService,
                $registrationPredictionRequestService,
                $predictionRequestProcessor,
                $loginTokenService
            );
        }

        if ($validated['purpose'] === 'password_reset' && empty($validated['password'])) {
            return response()->json([
                'success' => false,
                'message' => 'Password is required.',
                'errors' => ['password' => ['The password field is required.']],
            ], 422);
        }

        $verified = $otpVerificationService->verify(
            $validated['email'],
            $validated['otp'],
            $validated['purpose'],
        );

        if (! $verified) {
            return response()->json([
                'success' => false,
                'code' => 'INVALID_OTP',
                'message' => 'Invalid or expired OTP.',
            ], 422);
        }

        if ($validated['purpose'] === 'login') {
            $user = $loginTokenService->findUserByEmail($validated['email']);

            if (! $user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User account not found.',
                ], 404);
            }

            if (! $loginTokenService->isActive($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Your account is inactive.',
                ], 403);
            }

            $token = $loginTokenService->createSingleDeviceToken($user);

            return response()->json([
                'success' => true,
                'message' => 'Login successful.',
                'token' => $token,
                'token_type' => 'Bearer',
                'user' => $loginTokenService->safeUser($user->refresh()),
            ]);
        }

        if ($validated['purpose'] === 'password_reset') {
            $user = $loginTokenService->findUserByEmail($validated['email']);

            if (! $user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid or expired OTP.',
                ], 422);
            }

            $user->forceFill(['password' => Hash::make($validated['password'])])->save();
            $token = $loginTokenService->createSingleDeviceToken($user);

            return response()->json([
                'success' => true,
                'message' => 'Password reset successfully.',
                'token' => $token,
                'token_type' => 'Bearer',
                'user' => $loginTokenService->safeUser($user->refresh()),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'OTP verified successfully.',
        ]);
    }

    private function verifySignupOtp(
        array $validated,
        OtpVerificationService $otpVerificationService,
        RegistrationPredictionRequestService $registrationPredictionRequestService,
        PredictionRequestProcessor $predictionRequestProcessor,
        LoginTokenService $loginTokenService
    ): JsonResponse {
        $normalizedEmail = $otpVerificationService->normalizeEmail($validated['email']);

        if (empty($validated['password'])) {
            return response()->json([
                'success' => false,
                'message' => 'Password is required.',
                'errors' => ['password' => ['The password field is required.']],
            ], 422);
        }

        try {
            $registration = DB::transaction(function () use (
                $normalizedEmail,
                $validated,
                $registrationPredictionRequestService
            ): array|JsonResponse {
                $record = OtpRecord::query()
                    ->where('email', $normalizedEmail)
                    ->where('purpose', 'signup')
                    ->latest('created_at')
                    ->latest('id')
                    ->lockForUpdate()
                    ->first();

                if (! $record || ! Hash::check($validated['otp'], $record->otp_hash)) {
                    return $this->otpErrorResponse('INVALID_OTP', 'The OTP you entered is invalid.');
                }

                if ($record->used_at !== null) {
                    return $this->otpErrorResponse('OTP_ALREADY_USED', 'This OTP has already been used. Please request a new OTP.');
                }

                if ($record->expires_at->isPast()) {
                    return $this->otpErrorResponse('OTP_EXPIRED', 'The OTP has expired. Please request a new OTP.');
                }

                if ($registrationPredictionRequestService->emailExists($normalizedEmail) || User::query()->where('email', $normalizedEmail)->exists()) {
                    return $this->alreadyRegisteredResponse();
                }

                $pendingPayload = $record->pending_payload;

                if (! is_array($pendingPayload)) {
                    return $this->otpErrorResponse('INVALID_OTP', 'The OTP you entered is invalid.');
                }

                if (($pendingPayload['registration_only'] ?? false) === true) {
                    return [
                        'otp_record_id' => $record->id,
                        'prediction_request' => null,
                        'prediction_input' => null,
                        'name' => $pendingPayload['name'] ?: strtok($normalizedEmail, '@'),
                    ];
                }

                $predictionRequest = $registrationPredictionRequestService->createFromPendingPayload($pendingPayload);

                return [
                    'otp_record_id' => $record->id,
                    'prediction_request' => $predictionRequest,
                    'prediction_input' => $pendingPayload,
                ];
            });
        } catch (QueryException $exception) {
            if ($this->isUniqueConstraintViolation($exception)) {
                return $this->alreadyRegisteredResponse();
            }

            throw $exception;
        }

        if ($registration instanceof JsonResponse) {
            return $registration;
        }

        $predictionRequest = $registration['prediction_request'];

        if ($predictionRequest !== null) {
            try {
                $predictionRequestProcessor->processPast(
                    $predictionRequest,
                    $registration['prediction_input'],
                    $predictionRequest->birth_payload
                );
            } catch (ConnectionException $exception) {
                return $this->markPredictionFailed($predictionRequest, $exception, 504, $predictionRequestProcessor);
            } catch (Throwable $exception) {
                return $this->markPredictionFailed($predictionRequest, $exception, 502, $predictionRequestProcessor);
            }
        }

        $user = DB::transaction(function () use ($registration, $normalizedEmail, $validated): User {
            OtpRecord::query()
                ->whereKey($registration['otp_record_id'])
                ->lockForUpdate()
                ->firstOrFail()
                ->forceFill(['used_at' => now()])
                ->save();

            return User::query()->updateOrCreate(
                ['email' => $normalizedEmail],
                [
                    'name' => $registration['prediction_request']?->full_name ?? $registration['name'] ?? strtok($normalizedEmail, '@'),
                    'password' => Hash::make($validated['password']),
                    'email_verified_at' => now(),
                    'is_active' => true,
                ]
            );
        });

        $predictionRequest?->refresh();
        $token = $loginTokenService->createSingleDeviceToken($user);

        $payload = [
            'success' => true,
            'code' => 'REGISTRATION_COMPLETED',
            'message' => 'Registration completed successfully.',
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => $loginTokenService->safeUser($user->refresh()),
        ];

        if ($predictionRequest !== null) {
            $payload['prediction_request_id'] = $predictionRequest->id;
            $payload['request_uuid'] = $predictionRequest->request_uuid;
        }

        return response()->json($payload);
    }

    private function markPredictionFailed(
        PredictionRequest $predictionRequest,
        Throwable $exception,
        int $status,
        PredictionRequestProcessor $predictionRequestProcessor
    ): JsonResponse {
        Log::warning('Past prediction AstrologyAPI request failed.', [
            'request_uuid' => $predictionRequest->request_uuid,
            'status' => $status,
            'error' => $exception->getMessage(),
        ]);

        $predictionRequestProcessor->markFailed($predictionRequest, $exception);

        return response()->json([
            'success' => false,
            'request_uuid' => $predictionRequest->request_uuid,
            'status' => $predictionRequest->refresh()->status,
            'message' => 'Unable to create past prediction request.',
        ], $status);
    }

    private function otpErrorResponse(string $code, string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'code' => $code,
            'message' => $message,
        ], 422);
    }

    private function alreadyRegisteredResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'code' => 'EMAIL_ALREADY_REGISTERED',
            'message' => 'This email is already registered. Please log in to continue.',
        ], 409);
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return $exception->getCode() === '23000'
            || str_contains($message, 'unique')
            || str_contains($message, 'constraint');
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone_e164' => $user->phone_e164,
                'email_verified_at' => $user->email_verified_at?->toJSON(),
                'last_login_at' => $user->last_login_at?->toJSON(),
                'created_at' => $user->created_at?->toJSON(),
            ],
        ]);
    }

    public function profile(Request $request, UserProfileService $userProfileService): JsonResponse
    {
        return response()->json([
            'success' => true,
            'profile' => $userProfileService->profile($request->user()),
        ]);
    }

    public function updateProfile(Request $request, UserProfileService $userProfileService): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'phone_e164' => ['nullable', 'string', 'regex:/^\+\d{8,15}$/'],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ]);

        $user = $request->user();
        $updates = [
            'name' => trim($validated['name']),
            'country_code' => isset($validated['country_code']) ? strtoupper($validated['country_code']) : null,
            'phone_e164' => $validated['phone_e164'] ?? null,
        ];

        if (! empty($validated['password'])) {
            $updates['password'] = Hash::make($validated['password']);
        }

        $user->forceFill($updates)->save();

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully.',
            'profile' => $userProfileService->profile($user->refresh()),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        $this->updateLastActivityIfSupported($user);

        $currentAccessToken = $user->currentAccessToken();

        if ($currentAccessToken) {
            $currentAccessToken->delete();
        }

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully.',
        ]);
    }

    private function updateLastActivityIfSupported(User $user): void
    {
        if (Schema::hasColumn($user->getTable(), 'last_activity_at')) {
            $user->forceFill(['last_activity_at' => now()])->save();

            return;
        }

        if (Schema::hasColumn($user->getTable(), 'last_activity')) {
            $value = Schema::getColumnType($user->getTable(), 'last_activity') === 'integer'
                ? now()->timestamp
                : now();

            $user->forceFill(['last_activity' => $value])->save();
        }
    }

    private function maskEmail(string $email): string
    {
        [$localPart, $domain] = array_pad(explode('@', $email, 2), 2, '');

        $visible = mb_substr($localPart, 0, 2);

        return $domain === ''
            ? 'invalid-email'
            : $visible.'***@'.$domain;
    }
}
