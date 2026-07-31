<?php

namespace App\Services;

use App\Models\PredictionRequest;
use BadMethodCallException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

class PredictionCacheService extends BaseService
{
    private const COORDINATE_PRECISION = 4;

    public function coordinatePrecision(): int
    {
        return self::COORDINATE_PRECISION;
    }

    public function normalizedLatitude(float|int|string $latitude): float
    {
        return round((float) $latitude, self::COORDINATE_PRECISION);
    }

    public function normalizedLongitude(float|int|string $longitude): float
    {
        return round((float) $longitude, self::COORDINATE_PRECISION);
    }

    public function normalizedDate(string $dateOfBirth): string
    {
        return CarbonImmutable::parse($dateOfBirth)->format('Y-m-d');
    }

    public function normalizedTime(string $timeOfBirth): string
    {
        return CarbonImmutable::parse($timeOfBirth)->format('H:i:s');
    }

    public function fingerprint(array $attributes): string
    {
        $timezone = trim((string) ($attributes['timezone'] ?? 'Asia/Kolkata'));

        return hash('sha256', implode('|', [
            $this->normalizedDate((string) $attributes['date_of_birth']),
            $this->normalizedTime((string) $attributes['time_of_birth']),
            number_format($this->normalizedLatitude($attributes['latitude']), self::COORDINATE_PRECISION, '.', ''),
            number_format($this->normalizedLongitude($attributes['longitude']), self::COORDINATE_PRECISION, '.', ''),
            $timezone,
        ]));
    }

    public function findReusableCompleted(string $fingerprint, ?int $excludeId = null): ?PredictionRequest
    {
        return PredictionRequest::query()
            ->where('status', 'completed')
            ->where('chart_fingerprint', $fingerprint)
            ->whereNotNull('raw_astrology_response')
            ->whereNotNull('normalized_astrology_data')
            ->when($excludeId, fn ($query) => $query->whereKeyNot($excludeId))
            ->latest('completed_at')
            ->latest('id')
            ->first();
    }

    public function copyReusableAstrologyData(PredictionRequest $target, PredictionRequest $source): void
    {
        $auditSource = $this->auditSourceForReusable($source);

        $target->update([
            'status' => 'completed',
            'birth_payload' => $source->birth_payload,
            'raw_astrology_response' => $source->raw_astrology_response,
            'normalized_astrology_data' => $source->normalized_astrology_data,
            'api_call_count' => 0,
            'cache_hit' => true,
            'reused_from_request_id' => $auditSource->id,
            'error_message' => null,
            'completed_at' => now(),
        ]);
    }

    private function auditSourceForReusable(PredictionRequest $source): PredictionRequest
    {
        if (! $source->cache_hit || $source->reused_from_request_id === null) {
            return $source;
        }

        $original = PredictionRequest::query()
            ->whereKey($source->reused_from_request_id)
            ->where('status', 'completed')
            ->where('chart_fingerprint', $source->chart_fingerprint)
            ->whereNotNull('raw_astrology_response')
            ->whereNotNull('normalized_astrology_data')
            ->first();

        return $original ?? $source;
    }

    public function lock(string $fingerprint, callable $callback): mixed
    {
        try {
            return Cache::lock('astrology_api_result:'.$fingerprint, 120)->block(20, $callback);
        } catch (BadMethodCallException) {
            return $callback();
        }
    }
}
