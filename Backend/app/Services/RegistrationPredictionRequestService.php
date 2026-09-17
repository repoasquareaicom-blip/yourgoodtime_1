<?php

namespace App\Services;

use App\Models\PredictionRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RegistrationPredictionRequestService
{
    public const MIN_SIGNUP_AGE = 16;

    public const SIGNUP_AGE_ERROR = 'You must be 16 years or older to create an account.';

    public function __construct(
        private readonly AstrologyApiService $astrologyApiService,
        private readonly PredictionCacheService $predictionCacheService,
        private readonly OtpVerificationService $otpVerificationService
    ) {
    }

    /**
     * @throws ValidationException
     */
    public function payloadFromRequest(Request $request): array
    {
        $placeDetails = $request->input('place_of_birth_details', $request->input('placeOfBirthDetails', []));
        $placeDetails = is_array($placeDetails) ? $placeDetails : [];

        $input = array_merge($request->all(), [
            'full_name' => $request->input('full_name', $request->input('fullName')),
            'gender' => $request->input('gender'),
            'date_of_birth' => $request->input('date_of_birth', $request->input('dateOfBirth')),
            'time_of_birth' => $request->input('time_of_birth', $request->input('timeOfBirth')),
            'place_of_birth' => $request->input(
                'place_of_birth',
                $request->input('birth_place', $request->input('placeOfBirth', $placeDetails['place_name'] ?? null))
            ),
            'country' => $request->input('country', $placeDetails['country'] ?? null),
            'country_code' => $request->input('country_code', $placeDetails['country_code'] ?? null),
            'latitude' => $request->input('latitude', $placeDetails['latitude'] ?? null),
            'longitude' => $request->input('longitude', $placeDetails['longitude'] ?? null),
            'marriage_status' => $request->input('marriage_status', $request->input('marriageStatus')),
            'email' => $this->otpVerificationService->normalizeEmail((string) $request->input('email')),
        ]);

        $validated = Validator::make($input, [
            'full_name' => ['required', 'string', 'max:160'],
            'gender' => ['nullable', 'string', 'in:male,female,other'],
            'date_of_birth' => ['required', 'date_format:Y-m-d', function (string $attribute, mixed $value, \Closure $fail): void {
                try {
                    $dob = CarbonImmutable::createFromFormat('Y-m-d', (string) $value);
                } catch (\Throwable) {
                    $fail('Enter a valid date of birth.');
                    return;
                }

                if ($dob === false || $dob->format('Y-m-d') !== $value) {
                    $fail('Enter a valid date of birth.');
                    return;
                }

                $minimumDob = CarbonImmutable::now(config('app.timezone'))->startOfDay()->subYears(self::MIN_SIGNUP_AGE);
                if ($dob->startOfDay()->greaterThan($minimumDob)) {
                    $fail(self::SIGNUP_AGE_ERROR);
                }
            }],
            'time_of_birth' => ['required', 'date_format:H:i'],
            'timezone' => ['nullable', 'timezone'],
            'place_of_birth' => ['required', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:120'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'marriage_status' => ['nullable', 'string', 'in:married,unmarried'],
            'email' => ['required', 'email', 'max:254'],
        ])->validate();

        return array_merge($validated, [
            'timezone' => $validated['timezone'] ?? 'Asia/Kolkata',
            'country_code' => isset($validated['country_code']) ? strtoupper($validated['country_code']) : null,
            'latitude' => $this->predictionCacheService->normalizedLatitude($validated['latitude']),
            'longitude' => $this->predictionCacheService->normalizedLongitude($validated['longitude']),
        ]);
    }

    public function emailExists(string $email): bool
    {
        return PredictionRequest::query()
            ->where('email', $this->otpVerificationService->normalizeEmail($email))
            ->exists();
    }

    public function createFromPendingPayload(array $pendingPayload): PredictionRequest
    {
        $pendingPayload['email'] = $this->otpVerificationService->normalizeEmail((string) $pendingPayload['email']);
        $birthPayload = $this->astrologyApiService->buildBirthPayload($pendingPayload);

        return PredictionRequest::query()->create([
            'request_uuid' => (string) Str::uuid(),
            'chart_fingerprint' => $this->predictionCacheService->fingerprint($pendingPayload),
            'prediction_type' => 'past',
            'status' => 'pending',
            'full_name' => $pendingPayload['full_name'],
            'gender' => $pendingPayload['gender'] ?? null,
            'date_of_birth' => $pendingPayload['date_of_birth'],
            'time_of_birth' => $pendingPayload['time_of_birth'],
            'timezone' => $pendingPayload['timezone'] ?? 'Asia/Kolkata',
            'place_of_birth' => $pendingPayload['place_of_birth'],
            'country' => $pendingPayload['country'] ?? null,
            'country_code' => $pendingPayload['country_code'] ?? null,
            'latitude' => $pendingPayload['latitude'],
            'longitude' => $pendingPayload['longitude'],
            'marriage_status' => $pendingPayload['marriage_status'] ?? null,
            'email' => $pendingPayload['email'],
            'birth_payload' => $birthPayload,
            'cache_hit' => false,
            'submitted_at' => now(),
        ]);
    }
}
