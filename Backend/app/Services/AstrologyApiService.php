<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AstrologyApiService extends BaseService
{
    private const PAST_PREDICTION_ENDPOINTS = [
        'astro_details',
        'planets',
        'horo_chart/D1',
        'current_vdasha',
        'major_vdasha',
    ];

    /**
     * @throws ConnectionException
     */
    public function searchPlaces(string $search, int $maxRows = 10): Response
    {
        return $this->client(10)
            ->post($this->url('geo_details'), [
                'place' => $search,
                'maxRows' => (string) $maxRows,
            ]);
    }

    public function pastPredictionApiCallCount(): int
    {
        return count(self::PAST_PREDICTION_ENDPOINTS);
    }

    public function buildBirthPayload(array $input): array
    {
        $timezone = $input['timezone'] ?? 'Asia/Kolkata';
        $dateTime = CarbonImmutable::parse($input['date_of_birth'].' '.$input['time_of_birth'], $timezone);

        return [
            'day' => (int) $dateTime->format('j'),
            'month' => (int) $dateTime->format('n'),
            'year' => (int) $dateTime->format('Y'),
            'hour' => (int) $dateTime->format('G'),
            'min' => (int) $dateTime->format('i'),
            'lat' => round((float) $input['latitude'], 4),
            'lon' => round((float) $input['longitude'], 4),
            'tzone' => $this->timezoneToOffset($input['timezone'] ?? 'Asia/Kolkata'),
        ];
    }

    /**
     * @throws ConnectionException
     */
    public function fetchPastPredictionBundle(array $birthPayload): array
    {
        $responses = [];

        foreach (self::PAST_PREDICTION_ENDPOINTS as $endpoint) {
            $response = $this->post($endpoint, $birthPayload, 90);
            $key = str_replace('/', '_', $endpoint);
            $responses[$key] = $response;
        }

        return $responses;
    }

    public function normalizePastPredictionData(array $rawResponse, array $meta): array
    {
        $astroDetails = $rawResponse['astro_details'] ?? [];
        $planets = $this->extractPlanets($rawResponse['planets'] ?? []);
        $chart = $rawResponse['horo_chart_D1'] ?? [];
        $currentDasha = $rawResponse['current_vdasha'] ?? [];
        $majorDasha = $rawResponse['major_vdasha'] ?? [];

        return [
            'birth' => [
                'date_of_birth' => $meta['date_of_birth'] ?? null,
                'time_of_birth' => $meta['time_of_birth'] ?? null,
                'timezone' => $meta['timezone'] ?? 'Asia/Kolkata',
                'place_of_birth' => $meta['place_of_birth'] ?? null,
                'country' => $meta['country'] ?? null,
                'country_code' => $meta['country_code'] ?? null,
                'latitude' => $meta['latitude'] ?? null,
                'longitude' => $meta['longitude'] ?? null,
            ],
            'identity' => [
                'lagnam' => $this->firstValue($astroDetails, ['ascendant', 'ascendant_sign', 'lagna', 'lagnam']),
                'lagnam_lord' => $this->firstValue($astroDetails, ['ascendant_lord', 'lagna_lord', 'lagnam_lord']),
                'rasi' => $this->firstValue($astroDetails, ['sign', 'moon_sign', 'rasi']),
                'rasi_lord' => $this->firstValue($astroDetails, ['SignLord', 'sign_lord', 'rasi_lord']),
                'nakshatra' => $this->firstValue($astroDetails, ['Naksahtra', 'Nakshatra', 'nakshatra']),
                'nakshatra_lord' => $this->firstValue($astroDetails, ['NaksahtraLord', 'NakshatraLord', 'nakshatra_lord']),
                'nakshatra_pada' => $this->firstValue($astroDetails, ['Charan', 'charan', 'pada']),
            ],
            'dasha' => [
                'current_major_lord' => $this->firstValue($currentDasha, ['major.planet', 'major_lord', 'maha_dasha_lord']),
                'current_minor_lord' => $this->firstValue($currentDasha, ['minor.planet', 'minor_lord', 'antar_dasha_lord']),
                'current' => $currentDasha,
                'major' => $majorDasha,
            ],
            'chart' => [
                'chart_id' => 'D1',
                'data' => $chart,
            ],
            'planets' => $planets,
        ];
    }

    /**
     * @throws ConnectionException
     */
    private function post(string $endpoint, array $payload, int $timeoutSeconds = 30): array
    {
        $response = $this->client($timeoutSeconds)->post($this->url($endpoint), $payload);

        if ($response->failed()) {
            $message = $response->json('msg')
                ?? $response->json('message')
                ?? $response->json('error')
                ?? 'AstrologyAPI request failed with HTTP '.$response->status();

            if (is_array($message)) {
                $message = json_encode($message);
            }

            throw new RuntimeException((string) $message);
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new RuntimeException('AstrologyAPI returned an invalid JSON response.');
        }

        return $json;
    }

    private function client(int $timeoutSeconds): \Illuminate\Http\Client\PendingRequest
    {
        $token = $this->accessToken();

        return Http::timeout($timeoutSeconds)
            ->acceptJson()
            ->withHeaders([
                'Content-Type' => 'application/json',
                'x-astrologyapi-key' => $token,
            ]);
    }

    private function url(string $endpoint): string
    {
        return rtrim((string) config('services.astrology.base_url'), '/').'/'.ltrim($endpoint, '/');
    }

    private function accessToken(): string
    {
        $token = (string) config('services.astrology.access_token', config('services.astrology.api_key'));

        if ($token === '') {
            throw new RuntimeException('AstrologyAPI access token is not configured.');
        }

        return $token;
    }

    private function timezoneToOffset(string $timezone): float
    {
        $dateTime = CarbonImmutable::now($timezone);

        return $dateTime->getOffset() / 3600;
    }

    private function firstValue(array $payload, array $keys): mixed
    {
        foreach ($keys as $key) {
            $value = data_get($payload, $key);

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function extractPlanets(array $payload): array
    {
        $items = $payload['planets'] ?? $payload['data'] ?? $payload;

        if (! is_array($items)) {
            return [];
        }

        return array_values(array_filter($items, 'is_array'));
    }
}
