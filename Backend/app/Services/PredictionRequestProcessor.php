<?php

namespace App\Services;

use App\Models\PredictionRequest;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

class PredictionRequestProcessor
{
    public function __construct(
        private readonly AstrologyApiService $astrologyApiService,
        private readonly PredictionCacheService $predictionCacheService
    ) {
    }

    /**
     * @throws ConnectionException
     * @throws Throwable
     */
    public function processPast(PredictionRequest $predictionRequest, array $predictionInput, array $birthPayload): bool
    {
        return $this->predictionCacheService->lock(
            (string) $predictionRequest->chart_fingerprint,
            function () use ($birthPayload, $predictionInput, $predictionRequest): bool {
                $reusableRequest = $this->predictionCacheService->findReusableCompleted(
                    (string) $predictionRequest->chart_fingerprint,
                    $predictionRequest->id
                );

                if ($reusableRequest) {
                    $this->predictionCacheService->copyReusableAstrologyData($predictionRequest, $reusableRequest);

                    return true;
                }

                $rawResponse = $this->astrologyApiService->fetchPastPredictionBundle($birthPayload);
                $normalizedData = $this->astrologyApiService->normalizePastPredictionData($rawResponse, [
                    'full_name' => $predictionInput['full_name'],
                    'date_of_birth' => $predictionInput['date_of_birth'],
                    'time_of_birth' => $predictionInput['time_of_birth'],
                    'timezone' => $predictionInput['timezone'],
                    'place_of_birth' => $predictionInput['place_of_birth'],
                    'country' => $predictionInput['country'] ?? null,
                    'country_code' => $predictionInput['country_code'],
                    'latitude' => (float) $predictionInput['latitude'],
                    'longitude' => (float) $predictionInput['longitude'],
                ]);

                $predictionRequest->update([
                    'status' => 'completed',
                    'raw_astrology_response' => $rawResponse,
                    'normalized_astrology_data' => $normalizedData,
                    'api_call_count' => $this->astrologyApiService->pastPredictionApiCallCount(),
                    'cache_hit' => false,
                    'reused_from_request_id' => null,
                    'error_message' => null,
                    'completed_at' => now(),
                ]);

                return false;
            }
        );
    }

    public function markFailed(PredictionRequest $predictionRequest, Throwable $exception): void
    {
        $predictionRequest->update([
            'status' => 'failed',
            'error_message' => $exception->getMessage(),
        ]);
    }
}
