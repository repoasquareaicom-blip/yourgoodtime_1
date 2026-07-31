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

        $user = $request->user();
        $product = $accessService->activeProduct();
        $purchasedEntitlements = $accessService->purchasedEntitlements($user);
        $purchasedBlocks = $purchasedEntitlements
            ->map(fn ($entitlement) => $accessService->purchasedBlockPayload($entitlement))
            ->values();
        $nextPurchase = $accessService->nextPurchaseBlock($user, $predictionRequest);
        $futureResult = PredictionResult::query()
            ->where('prediction_request_id', $predictionRequest->id)
            ->where('prediction_type', 'future')
            ->where('status', 'completed')
            ->first();
        $hasPurchasedFuture = $purchasedEntitlements->isNotEmpty();

        return response()->json([
            'success' => true,
            'has_purchased_future' => $hasPurchasedFuture,
            'purchased_blocks' => $purchasedBlocks,
            'next_purchase' => $nextPurchase,
            'future_prediction_enabled' => $hasPurchasedFuture,
            'future_result_ready' => $futureResult !== null,
            'future_result_id' => $futureResult?->id,
            'can_purchase' => $product !== null && (bool) $nextPurchase['can_purchase'],
            'entitlement' => $accessService->entitlementPayload($purchasedEntitlements->last()),
            'product' => $accessService->productPayload($product),
        ]);
    }
}
