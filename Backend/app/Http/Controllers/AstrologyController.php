<?php

namespace App\Http\Controllers;

use App\Services\AstrologyApiService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class AstrologyController extends Controller
{
    public function startSession(): JsonResponse
    {
        $sessionUuid = (string) Str::uuid();
        $now = now();

        DB::transaction(function () use ($sessionUuid, $now): void {
            DB::table('astrology_sessions')->insert([
                'session_uuid' => $sessionUuid,
                'session_type' => 'birth_details',
                'started_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });

        return response()->json([
            'success' => true,
            'session_uuid' => $sessionUuid,
        ]);
    }

    public function places(Request $request, AstrologyApiService $astrologyApiService): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        $sessionUuid = trim((string) $request->query('session_uuid', ''));
        $maxRows = min(20, max(1, (int) $request->query('maxRows', 10)));

        if ($search === '') {
            return response()->json([
                'success' => true,
                'data' => [],
            ]);
        }

        $session = $this->findSession($sessionUuid);

        if (! $session) {
            return response()->json([
                'success' => false,
                'data' => [],
                'message' => 'Invalid astrology session',
            ], 400);
        }

        $normalizedQuery = Str::lower($search);
        $cacheKey = 'astrology_places:'.$normalizedQuery.':'.$maxRows;

        try {
            if (Cache::has($cacheKey)) {
                $cachedPlaces = Cache::get($cacheKey, []);

                $this->recordApiLog([
                    'session_id' => $session->session_id,
                    'search_query' => $normalizedQuery,
                    'cache_hit' => true,
                    'external_api_called' => false,
                    'estimated_cost' => 0,
                    'response_status' => 200,
                    'response_time_ms' => 0,
                    'result_count' => count($cachedPlaces),
                ]);

                return response()->json([
                    'success' => true,
                    'data' => $cachedPlaces,
                ]);
            }

            $startedAt = microtime(true);
            $response = $astrologyApiService->searchPlaces($search, $maxRows);
            $responseTimeMs = (int) round((microtime(true) - $startedAt) * 1000);

            if ($response->failed()) {
                $errorMessage = $this->failureMessage($response->status());

                $this->recordApiLog([
                    'session_id' => $session->session_id,
                    'search_query' => $normalizedQuery,
                    'cache_hit' => false,
                    'external_api_called' => true,
                    'estimated_cost' => 0.10,
                    'response_status' => $response->status(),
                    'response_time_ms' => $responseTimeMs,
                    'result_count' => 0,
                    'error_message' => $errorMessage,
                ]);

                $this->logFailure($response->status(), $search, [
                    'response' => $response->json(),
                ]);

                return response()->json([
                    'success' => false,
                    'data' => [],
                    'message' => $this->failureMessage($response->status()),
                ], $this->clientStatus($response->status()));
            }

            $places = $this->simplifyPlaces($response->json());

            Cache::put($cacheKey, $places, now()->addDays(30));

            $this->recordApiLog([
                'session_id' => $session->session_id,
                'search_query' => $normalizedQuery,
                'cache_hit' => false,
                'external_api_called' => true,
                'estimated_cost' => 0.10,
                'response_status' => $response->status(),
                'response_time_ms' => $responseTimeMs,
                'result_count' => count($places),
            ]);

            return response()->json([
                'success' => true,
                'data' => $places,
            ]);
        } catch (ConnectionException $exception) {
            $this->recordApiLog([
                'session_id' => $session->session_id,
                'search_query' => $normalizedQuery,
                'cache_hit' => false,
                'external_api_called' => true,
                'estimated_cost' => 0.10,
                'response_status' => 504,
                'response_time_ms' => 0,
                'result_count' => 0,
                'error_message' => $exception->getMessage(),
            ]);

            $this->logFailure('timeout', $search, [
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'data' => [],
                'message' => 'Unable to fetch places',
            ], 504);
        } catch (Throwable $exception) {
            $this->recordApiLog([
                'session_id' => $session->session_id,
                'search_query' => $normalizedQuery,
                'cache_hit' => false,
                'external_api_called' => true,
                'estimated_cost' => 0.10,
                'response_status' => 500,
                'response_time_ms' => 0,
                'result_count' => 0,
                'error_message' => $exception->getMessage(),
            ]);

            $this->logFailure(500, $search, [
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'data' => [],
                'message' => 'Unable to fetch places',
            ], 500);
        }
    }

    public function selectPlace(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_uuid' => ['required', 'string'],
            'place_name' => ['required', 'string'],
            'country' => ['nullable', 'string'],
            'latitude' => ['required', 'numeric'],
            'longitude' => ['required', 'numeric'],
        ]);

        $updated = DB::transaction(function () use ($validated): int {
            return DB::table('astrology_sessions')
                ->where('session_uuid', $validated['session_uuid'])
                ->update([
                    'selected_place_name' => $validated['place_name'],
                    'selected_country' => $validated['country'] ?? null,
                    'selected_latitude' => $validated['latitude'],
                    'selected_longitude' => $validated['longitude'],
                    'completed_at' => now(),
                    'updated_at' => now(),
                ]);
        });

        if ($updated === 0) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid astrology session',
                'data' => null,
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => null,
        ]);
    }

    private function simplifyPlaces(mixed $payload): array
    {
        $items = $this->extractItems($payload);

        return array_values(array_map(function (array $place): array {
            return [
                'place_name' => (string) ($place['place_name'] ?? $place['name'] ?? $place['place'] ?? $place['city'] ?? ''),
                'country' => (string) ($place['country'] ?? $place['country_name'] ?? ''),
                'country_code' => (string) ($place['country_code'] ?? $place['countryCode'] ?? ''),
                'latitude' => (float) ($place['latitude'] ?? $place['lat'] ?? 0),
                'longitude' => (float) ($place['longitude'] ?? $place['lng'] ?? $place['lon'] ?? 0),
            ];
        }, $items));
    }

    private function extractItems(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        $items = $payload['data'] ?? $payload['geonames'] ?? $payload['places'] ?? $payload['results'] ?? $payload;

        if (! is_array($items)) {
            return [];
        }

        return array_values(array_filter($items, 'is_array'));
    }

    private function findSession(string $sessionUuid): ?object
    {
        if ($sessionUuid === '') {
            return null;
        }

        return DB::table('astrology_sessions')
            ->where('session_uuid', $sessionUuid)
            ->first(['session_id', 'session_uuid']);
    }

    private function recordApiLog(array $attributes): void
    {
        DB::transaction(function () use ($attributes): void {
            DB::table('astrology_api_logs')->insert(array_merge([
                'api_name' => 'place_search',
                'request_type' => 'search',
                'error_message' => null,
                'created_at' => now(),
            ], $attributes));
        });
    }

    private function clientStatus(int $status): int
    {
        return in_array($status, [400, 401, 403, 404, 429], true) ? $status : 500;
    }

    private function failureMessage(int $status): string
    {
        return match ($status) {
            400 => 'Invalid place search request',
            401, 403 => 'Unable to fetch places',
            404 => 'No matching places found',
            429 => 'Too many place search requests',
            default => 'Unable to fetch places',
        };
    }

    private function logFailure(int|string $status, string $search, array $context = []): void
    {
        Log::warning('Astrology place search failed.', array_merge([
            'status' => $status,
            'search' => $search,
        ], $context));
    }
}
