<?php

namespace App\Http\Controllers;

use App\Services\FuturePredictionAccessService;
use Illuminate\Http\JsonResponse;

class PredictionProductController extends Controller
{
    public function activeFuture(FuturePredictionAccessService $accessService): JsonResponse
    {
        $product = $accessService->activeProduct();

        return response()->json([
            'success' => true,
            'product' => $accessService->productPayload($product),
        ]);
    }
}
