<?php

namespace App\Http\Controllers;

use App\Models\PredictionRequest;
use App\Models\PredictionResult;
use App\Models\PredictionEntitlement;
use App\Services\AstrologyApiService;
use App\Services\FuturePredictionAccessService;
use App\Services\PredictionCacheService;
use App\Services\Prediction\PredictionEngineService;
use App\Services\Prediction\PredictionResponseBuilder;
use App\Services\Prediction\PredictionTimelineSummaryService;
use App\Services\PredictionRequestProcessor;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class PredictionController extends Controller
{
    public function result(
        Request $request,
        PredictionEngineService $predictionEngineService,
        PredictionResponseBuilder $predictionResponseBuilder,
        FuturePredictionAccessService $futurePredictionAccessService,
        PredictionTimelineSummaryService $timelineSummaryService
    ): JsonResponse {
        $validator = Validator::make($request->all(), [
            'id' => ['required', 'integer'],
            'type' => ['required', 'string', 'in:past,future'],
            'request_uuid' => ['nullable', 'uuid'],
            'entitlement_id' => ['nullable', 'integer'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();
        $predictionRequest = PredictionRequest::query()->find((int) $validated['id']);

        if ($predictionRequest === null) {
            return response()->json([
                'success' => false,
                'message' => 'Prediction request not found.',
            ], 404);
        }

        if (! $this->requestUuidIsAuthorized($predictionRequest, $validated['request_uuid'] ?? null)) {
            return $this->unauthorizedResultResponse();
        }

        $futureEntitlement = $validated['type'] === 'future'
            ? $this->futureEntitlementForGeneration($predictionRequest, $validated['entitlement_id'] ?? null, $request)
            : null;

        if ($validated['type'] === 'future' && $futureEntitlement === null) {
            return response()->json([
                'success' => false,
                'message' => 'Future prediction access is not active for this request.',
            ], 403);
        }

        $storedResult = $this->completedStoredResult($predictionRequest, $validated['type'], $futureEntitlement);

        if ($storedResult !== null && $this->storedResultIsCurrent($storedResult, $predictionRequest, $validated['type'], $timelineSummaryService)) {
            return $this->storedResultResponse($storedResult, 'Prediction result loaded successfully.');
        }

        try {
            $timeline = $predictionEngineService->preparePredictionTimeline(
                $predictionRequest->id,
                $validated['type'],
                $futureEntitlement
            );
            $result = $predictionResponseBuilder->buildTimeline($timeline);
            $warnings = $this->predictionWarnings($result['prediction_videos'] ?? []);
            $payload = [
                'prediction_request_id' => $result['prediction_request_id'] ?? $predictionRequest->id,
                'type' => $result['prediction_type'] ?? $validated['type'],
                'identity_video' => $result['identity_video'] ?? null,
                'prediction_videos' => $result['prediction_videos'] ?? [],
                'summary' => $result['summary'] ?? [],
                'warnings' => $warnings,
            ];

            if (! $this->resultPayloadHasPlayableVideo($payload)) {
                throw new \RuntimeException('No matching prediction video is available for these birth details.');
            }

            $storedResult = $this->persistResult($predictionRequest, $validated['type'], $payload, $futureEntitlement);

            if ($futureEntitlement !== null && $futureEntitlement->generated_prediction_result_id === null) {
                $futureEntitlement->forceFill(['generated_prediction_result_id' => $storedResult->id])->save();
            }

            return $this->storedResultResponse($storedResult, 'Prediction result generated successfully.');
        } catch (Throwable $exception) {
            $this->persistFailedResult($predictionRequest, $validated['type'], $futureEntitlement);

            Log::error('Prediction result generation failed.', [
                'prediction_request_id' => $predictionRequest->id,
                'prediction_type' => $validated['type'],
                'exception' => $exception,
            ]);

            $isKnownGenerationIssue = $exception instanceof \RuntimeException;

            return response()->json([
                'success' => false,
                'code' => $isKnownGenerationIssue ? 'NO_PLAYABLE_PREDICTION' : 'PREDICTION_GENERATION_FAILED',
                'message' => $isKnownGenerationIssue
                    ? $exception->getMessage()
                    : 'Unable to generate prediction result.',
            ], 500);
        }
    }

    public function storedResult(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make(array_merge($request->query(), ['id' => $id]), [
            'id' => ['required', 'integer'],
            'type' => ['required', 'string', 'in:past,future'],
            'request_uuid' => ['required', 'uuid'],
            'entitlement_id' => ['nullable', 'integer'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();
        $predictionRequest = PredictionRequest::query()->find((int) $validated['id']);

        if ($predictionRequest === null) {
            return response()->json([
                'success' => false,
                'message' => 'Prediction request not found.',
            ], 404);
        }

        if (! $this->requestUuidIsAuthorized($predictionRequest, $validated['request_uuid'])) {
            return $this->unauthorizedResultResponse();
        }

        if ($validated['type'] === 'future') {
            if (isset($validated['entitlement_id'])) {
                $entitlement = $this->futureEntitlementForGeneration($predictionRequest, (int) $validated['entitlement_id'], $request);

                if ($entitlement === null) {
                    return $this->unauthorizedResultResponse();
                }

                $storedResult = $this->completedStoredResult($predictionRequest, 'future', $entitlement);

                if ($storedResult === null) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Stored prediction result not found.',
                    ], 404);
                }

                return $this->storedResultResponse($storedResult, 'Prediction result loaded successfully.');
            }

            return $this->combinedFutureResultResponse($predictionRequest);
        }

        $storedResult = $this->completedStoredResult($predictionRequest, $validated['type']);

        if ($storedResult === null) {
            return response()->json([
                'success' => false,
                'message' => 'Stored prediction result not found.',
            ], 404);
        }

        return $this->storedResultResponse($storedResult, 'Prediction result loaded successfully.');
    }

    public function sessionStatus(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'prediction_request_id' => ['required', 'integer'],
            'prediction_type' => ['nullable', 'string', 'in:past,future'],
            'request_uuid' => ['required', 'uuid'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();
        $predictionType = $validated['prediction_type'] ?? 'past';
        $predictionRequest = PredictionRequest::query()
            ->whereKey((int) $validated['prediction_request_id'])
            ->where('prediction_type', $predictionType)
            ->first();

        if ($predictionRequest === null || ! $this->requestUuidIsAuthorized($predictionRequest, $validated['request_uuid'])) {
            return response()->json([
                'success' => true,
                'message' => 'Prediction session is invalid.',
                'data' => ['valid' => false],
            ]);
        }

        $storedResult = $this->completedStoredResult($predictionRequest, $predictionType);

        return response()->json([
            'success' => true,
            'message' => 'Prediction session status loaded successfully.',
            'data' => [
                'valid' => true,
                'prediction_request_id' => $predictionRequest->id,
                'prediction_type' => $predictionRequest->prediction_type,
                'otp_verified' => true,
                'result_ready' => $storedResult !== null,
                'result_id' => $storedResult?->id,
                'name' => $predictionRequest->full_name,
                'masked_email' => $this->maskEmail($predictionRequest->email),
                'status' => $storedResult?->status ?? $predictionRequest->status,
                'created_at' => $predictionRequest->created_at?->toJSON(),
                'generated_at' => $storedResult?->generated_at?->toJSON(),
            ],
        ]);
    }

    public function preparePastPrediction(
        Request $request,
        PredictionEngineService $predictionEngineService,
        PredictionResponseBuilder $predictionResponseBuilder
    ): JsonResponse
    {
        if (! $request->has('prediction_request_id') && ! $request->has('prediction_type')) {
            $validated = $request->validate([
                'request_id' => ['required', 'integer', 'exists:prediction_requests,id'],
            ]);

            $session = $predictionEngineService->preparePastPrediction((int) $validated['request_id']);

            return response()->json(
                $predictionResponseBuilder->build($session, $request->boolean('debug'))
            );
        }

        $validated = $request->validate([
            'prediction_request_id' => ['required', 'integer', 'exists:prediction_requests,id'],
            'prediction_type' => ['required', 'string', 'in:past,future'],
        ]);

        $timeline = $predictionEngineService->preparePredictionTimeline(
            (int) $validated['prediction_request_id'],
            $validated['prediction_type']
        );

        return response()->json(
            $predictionResponseBuilder->buildTimeline($timeline, $request->boolean('debug'))
        );
    }

    public function past(
        Request $request,
        AstrologyApiService $astrologyApiService,
        PredictionCacheService $predictionCacheService,
        PredictionRequestProcessor $predictionRequestProcessor
    ): JsonResponse
    {
        $this->preparePredictionInput($request);

        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:160'],
            'gender' => ['nullable', 'string', 'in:male,female,other'],
            'date_of_birth' => ['required', 'date_format:Y-m-d'],
            'time_of_birth' => ['required', 'date_format:H:i'],
            'timezone' => ['nullable', 'timezone'],
            'place_of_birth' => ['required', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:120'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'marriage_status' => ['nullable', 'string', 'in:married,unmarried'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);

        $predictionInput = array_merge($validated, [
            'timezone' => $validated['timezone'] ?? 'Asia/Kolkata',
            'country_code' => isset($validated['country_code']) ? strtoupper($validated['country_code']) : null,
            'latitude' => $predictionCacheService->normalizedLatitude($validated['latitude']),
            'longitude' => $predictionCacheService->normalizedLongitude($validated['longitude']),
            'email' => isset($validated['email']) ? strtolower(trim($validated['email'])) : null,
        ]);
        $birthPayload = $astrologyApiService->buildBirthPayload($predictionInput);
        $chartFingerprint = $predictionCacheService->fingerprint($predictionInput);

        if ($predictionInput['email'] && PredictionRequest::query()->where('email', $predictionInput['email'])->exists()) {
            return $this->alreadyRegisteredResponse();
        }

        try {
            $predictionRequest = PredictionRequest::create([
                'request_uuid' => (string) Str::uuid(),
                'chart_fingerprint' => $chartFingerprint,
                'prediction_type' => 'past',
                'status' => 'pending',
                'full_name' => $predictionInput['full_name'],
                'gender' => $predictionInput['gender'] ?? null,
                'date_of_birth' => $predictionInput['date_of_birth'],
                'time_of_birth' => $predictionInput['time_of_birth'],
                'timezone' => $predictionInput['timezone'],
                'place_of_birth' => $predictionInput['place_of_birth'],
                'country' => $predictionInput['country'] ?? null,
                'country_code' => $predictionInput['country_code'],
                'latitude' => $predictionInput['latitude'],
                'longitude' => $predictionInput['longitude'],
                'marriage_status' => $predictionInput['marriage_status'] ?? null,
                'email' => $predictionInput['email'],
                'birth_payload' => $birthPayload,
                'cache_hit' => false,
                'submitted_at' => now(),
            ]);
        } catch (QueryException $exception) {
            if ($this->isUniqueConstraintViolation($exception)) {
                return $this->alreadyRegisteredResponse();
            }

            throw $exception;
        }

        try {
            $cached = $predictionRequestProcessor->processPast($predictionRequest, $predictionInput, $birthPayload);

            $predictionRequest->refresh();

            return response()->json([
                'success' => true,
                'prediction_request_id' => $predictionRequest->id,
                'request_uuid' => $predictionRequest->request_uuid,
                'status' => $predictionRequest->status,
                'message' => 'Past prediction request submitted successfully.',
                'cached' => $cached,
            ], 201);
        } catch (ConnectionException $exception) {
            return $this->markFailed($predictionRequest, $exception, 504, $predictionRequestProcessor);
        } catch (Throwable $exception) {
            return $this->markFailed($predictionRequest, $exception, 502, $predictionRequestProcessor);
        }
    }

    private function preparePredictionInput(Request $request): void
    {
        $placeDetails = $request->input('place_of_birth_details', $request->input('placeOfBirthDetails', []));
        $placeDetails = is_array($placeDetails) ? $placeDetails : [];

        $request->merge([
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
        ]);
    }

    private function markFailed(
        PredictionRequest $predictionRequest,
        Throwable $exception,
        int $status,
        PredictionRequestProcessor $predictionRequestProcessor
    ): JsonResponse
    {
        Log::warning('Past prediction AstrologyAPI request failed.', [
            'request_uuid' => $predictionRequest->request_uuid,
            'status' => $status,
            'error' => $exception->getMessage(),
        ]);

        $predictionRequestProcessor->markFailed($predictionRequest, $exception);

        return response()->json([
            'success' => false,
            'request_uuid' => $predictionRequest->request_uuid,
            'status' => $predictionRequest->status,
            'message' => 'Unable to create past prediction request.',
        ], $status);
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

    private function predictionWarnings(array $predictionVideos): array
    {
        return array_values(array_filter(array_map(
            fn (array $video): ?array => empty($video['warning'])
                ? null
                : [
                    'age' => $video['age'] ?? null,
                    'system_year' => $video['system_year'] ?? null,
                    'warning' => $video['warning'],
                ],
            $predictionVideos
        )));
    }

    private function futureEntitlementForGeneration(PredictionRequest $predictionRequest, ?int $entitlementId = null, ?Request $request = null): ?PredictionEntitlement
    {
        if ($entitlementId !== null) {
            $currentYear = (int) now(config('services.prediction_billing.business_timezone', 'Asia/Kolkata'))->format('Y');
            $query = PredictionEntitlement::query()
                ->whereKey($entitlementId)
                ->where('prediction_request_id', $predictionRequest->id)
                ->where('prediction_type', 'future')
                ->where('status', 'ACTIVE')
                ->where('access_end_year', '>', $currentYear);

            if ($request?->user() !== null) {
                $query->where('user_id', $request->user()->id);
            }

            return $query->first();
        }

        $baseQuery = PredictionEntitlement::query()
            ->where('prediction_request_id', $predictionRequest->id)
            ->where('prediction_type', 'future')
            ->where('status', 'ACTIVE')
            ->orderBy('access_start_year');

        $missingResult = (clone $baseQuery)
            ->whereNull('generated_prediction_result_id')
            ->first();

        return $missingResult ?? (clone $baseQuery)->latest('access_end_year')->first();
    }

    private function combinedFutureResultResponse(PredictionRequest $predictionRequest): JsonResponse
    {
        $results = PredictionResult::query()
            ->with('predictionRequest')
            ->where('prediction_request_id', $predictionRequest->id)
            ->where('prediction_type', 'future')
            ->where('status', 'completed')
            ->whereNotNull('prediction_entitlement_id')
            ->get()
            ->sortBy(function (PredictionResult $result): int {
                return (int) (PredictionEntitlement::query()->whereKey($result->prediction_entitlement_id)->value('access_start_year') ?? 0);
            })
            ->values();

        if ($results->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Stored prediction result not found.',
            ], 404);
        }

        $firstPayload = $results->first()->result_payload ?: [];
        $predictionVideos = $results
            ->flatMap(fn (PredictionResult $result) => $result->result_payload['prediction_videos'] ?? [])
            ->sortBy(fn (array $group): int => (int) ($group['system_year_from'] ?? $group['system_year'] ?? 0))
            ->values()
            ->all();

        $payload = [
            'prediction_request_id' => $predictionRequest->id,
            'type' => 'future',
            'prediction_type' => 'future',
            'identity_video' => $firstPayload['identity_video'] ?? null,
            'prediction_videos' => $predictionVideos,
            'summary' => [
                'total_blocks' => $results->count(),
                'grouped_result_count' => count($predictionVideos),
            ],
            'result_id' => $results->last()->id,
            'result_ids' => $results->pluck('id')->all(),
            'status' => 'completed',
            'generated_at' => optional($results->last()->generated_at)->toJSON(),
        ];

        return response()->json([
            'success' => true,
            'message' => 'Prediction result loaded successfully.',
            'data' => $payload,
        ]);
    }

    private function completedStoredResult(PredictionRequest $predictionRequest, string $predictionType, ?PredictionEntitlement $entitlement = null): ?PredictionResult
    {
        $query = PredictionResult::query()
            ->where('prediction_request_id', $predictionRequest->id)
            ->where('prediction_type', $predictionType)
            ->where('status', 'completed');

        if ($predictionType === 'future' && $entitlement !== null) {
            $query->where('prediction_entitlement_id', $entitlement->id);
        }

        $storedResult = $query->first();

        if ($storedResult !== null && ! $this->resultPayloadHasPlayableVideo($storedResult->result_payload ?? [])) {
            return null;
        }

        return $storedResult;
    }

    private function storedResultIsCurrent(
        PredictionResult $predictionResult,
        PredictionRequest $predictionRequest,
        string $predictionType,
        PredictionTimelineSummaryService $timelineSummaryService
    ): bool {
        if ($predictionType !== 'past') {
            return true;
        }

        if (! filled(data_get($predictionResult->result_payload, 'identity_video.video_id'))) {
            return false;
        }

        if ($predictionRequest->date_of_birth === null) {
            return true;
        }

        $currentRange = $timelineSummaryService->pastRange(
            CarbonImmutable::instance($predictionRequest->date_of_birth),
            CarbonImmutable::now(config('services.prediction_billing.business_timezone', 'Asia/Kolkata'))
        );

        if (! $currentRange['available'] || ! $currentRange['end_year']) {
            return true;
        }

        $payload = $predictionResult->result_payload;
        $storedEndYear = data_get($payload, 'summary.to_year')
            ?? data_get($payload, 'prediction_window.to')
            ?? data_get($payload, 'prediction_videos.0.system_year_to')
            ?? data_get($payload, 'prediction_videos.0.system_year');

        if (! is_numeric($storedEndYear)) {
            return false;
        }

        return (int) $storedEndYear >= (int) $currentRange['end_year'];
    }

    private function persistResult(PredictionRequest $predictionRequest, string $predictionType, array $payload, mixed $entitlement = null): PredictionResult
    {
        $identity = [
            'prediction_request_id' => $predictionRequest->id,
            'prediction_type' => $predictionType,
        ];

        if ($predictionType === 'future' && $entitlement !== null) {
            $identity['prediction_entitlement_id'] = $entitlement->id;
        }

        return PredictionResult::query()->updateOrCreate(
            $identity,
            [
                'user_id' => $entitlement?->user_id ?? $this->userIdForPredictionRequest($predictionRequest),
                'prediction_entitlement_id' => $entitlement?->id,
                'identity_video_id' => data_get($payload, 'identity_video.video_id'),
                'result_payload' => $payload,
                'summary_payload' => $payload['summary'] ?? [],
                'warnings_payload' => $payload['warnings'] ?? [],
                'status' => 'completed',
                'generated_at' => now(),
            ]
        );
    }

    private function persistFailedResult(PredictionRequest $predictionRequest, string $predictionType, mixed $entitlement = null): void
    {
        $identity = [
            'prediction_request_id' => $predictionRequest->id,
            'prediction_type' => $predictionType,
        ];

        if ($predictionType === 'future' && $entitlement !== null) {
            $identity['prediction_entitlement_id'] = $entitlement->id;
        }

        PredictionResult::query()->updateOrCreate(
            $identity,
            [
                'identity_video_id' => null,
                'user_id' => $entitlement?->user_id ?? $this->userIdForPredictionRequest($predictionRequest),
                'result_payload' => [],
                'summary_payload' => [],
                'warnings_payload' => [],
                'status' => 'failed',
                'generated_at' => now(),
            ]
        );
    }

    private function resultPayloadHasPlayableVideo(array $payload): bool
    {
        if (filled(data_get($payload, 'identity_video.video_id'))) {
            return true;
        }

        foreach ((array) data_get($payload, 'prediction_videos', []) as $videoGroup) {
            $videoIds = data_get($videoGroup, 'video_ids', []);

            if (is_array($videoIds) && count(array_filter($videoIds, fn (mixed $videoId): bool => filled($videoId))) > 0) {
                return true;
            }
        }

        return false;
    }

    private function userIdForPredictionRequest(PredictionRequest $predictionRequest): ?int
    {
        if (! filled($predictionRequest->email)) {
            return null;
        }

        return \App\Models\User::query()
            ->where('email', $predictionRequest->email)
            ->value('id');
    }

    private function storedResultResponse(PredictionResult $predictionResult, string $message): JsonResponse
    {
        $payload = $predictionResult->result_payload;
        $payload['result_id'] = $predictionResult->id;
        $payload['status'] = $predictionResult->status;
        $payload['generated_at'] = $predictionResult->generated_at?->toJSON();

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $payload,
        ]);
    }

    private function requestUuidIsAuthorized(PredictionRequest $predictionRequest, ?string $requestUuid): bool
    {
        return $requestUuid === null || hash_equals($predictionRequest->request_uuid, $requestUuid);
    }

    private function maskEmail(?string $email): ?string
    {
        if (! $email || ! str_contains($email, '@')) {
            return null;
        }

        [$localPart, $domain] = explode('@', $email, 2);
        $firstCharacter = substr($localPart, 0, 1);

        return $firstCharacter.'***@'.$domain;
    }

    private function unauthorizedResultResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'You are not authorised to access this prediction result.',
        ], 403);
    }
}
