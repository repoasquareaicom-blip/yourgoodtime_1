<?php

namespace App\Http\Controllers;

use App\Services\AstrologyApiService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class AshtakavargaResearchController extends Controller
{
    public function show(Request $request, AstrologyApiService $astrologyApi): JsonResponse
    {
        $validated = $request->validate([
            'date_of_birth' => ['required', 'date_format:Y-m-d'],
            'time_of_birth' => ['required', 'date_format:H:i'],
            'birth_place' => ['required', 'string', 'max:255'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'timezone' => ['required', 'timezone'],
        ]);

        try {
            $payload = $astrologyApi->buildBirthPayload($validated);
            $data = $astrologyApi->fetchSarvashtak($payload);

            return response()->json([
                'success' => true,
                'endpoint' => 'sarvashtak',
                'request_payload' => $payload,
                'data' => $data,
            ]);
        } catch (ConnectionException) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to reach AstrologyAPI.',
            ], 502);
        } catch (RuntimeException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 502);
        } catch (Throwable) {
            return response()->json([
                'success' => false,
                'message' => 'Ashtakavarga lookup failed.',
            ], 500);
        }
    }
}
