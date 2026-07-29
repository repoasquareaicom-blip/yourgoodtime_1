<?php

namespace App\Http\Controllers;

use App\Models\PredictionRequest;
use App\Models\PredictionResult;
use App\Services\FuturePredictionAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FutureAccessController extends Controller
{
    public function show(Request $request, FuturePredictionAccessService $accessService): JsonResponse
    {
        $validated = $request->validate([
            'prediction_request_id' => ['required', 'integer', 'exists:prediction_requests,id'],
            'request_uuid' => ['required', 'uuid'],
        ]);

        $predictionRequest = PredictionRequest::query()->findOrFail($validated['prediction_request_id']);

        if ($request->user()?->email !== null && $predictionRequest->email !== null && ! hash_equals($request->user()->email, $predictionRequest->email)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorised to access this prediction.',
            ], 403);
        }

        if (! hash_equals($predictionRequest->request_uuid, $validated['request_uuid'])) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorised to access this prediction.',
            ], 403);
        }

        $product = $accessService->activeProduct();
        $entitlement = $accessService->activeEntitlement($predictionRequest);
        $futureResult = $entitlement === null
            ? null
            : PredictionResult::query()
                ->where('prediction_request_id', $predictionRequest->id)
                ->where('prediction_type', 'future')
                ->where('status', 'completed')
                ->first();

        return response()->json([
            'success' => true,
            'future_prediction_enabled' => $entitlement !== null,
            'future_result_ready' => $futureResult !== null,
            'future_result_id' => $futureResult?->id,
            'can_purchase' => $entitlement === null && $product !== null,
            'entitlement' => $accessService->entitlementPayload($entitlement),
            'product' => $accessService->productPayload($product),
        ]);
    }
}
