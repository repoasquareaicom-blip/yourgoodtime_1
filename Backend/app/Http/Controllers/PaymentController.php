<?php

namespace App\Http\Controllers;

use App\Models\PaymentTransaction;
use App\Models\PredictionRequest;
use App\Services\FuturePredictionAccessService;
use App\Services\RazorpayPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class PaymentController extends Controller
{
    public function createFutureOrder(
        Request $request,
        FuturePredictionAccessService $accessService,
        RazorpayPaymentService $paymentService
    ): JsonResponse {
        $validated = $request->validate([
            'prediction_request_id' => ['required', 'integer', 'exists:prediction_requests,id'],
            'request_uuid' => ['required', 'uuid'],
            'product_id' => ['required', 'integer', 'exists:prediction_products,id'],
        ]);

        $predictionRequest = PredictionRequest::query()->findOrFail($validated['prediction_request_id']);

        if ($predictionRequest->email !== null && ! hash_equals($predictionRequest->email, $request->user()->email)) {
            return response()->json(['success' => false, 'message' => 'You are not authorised to purchase this prediction.'], 403);
        }

        if (! hash_equals($predictionRequest->request_uuid, $validated['request_uuid'])) {
            return response()->json(['success' => false, 'message' => 'You are not authorised to purchase this prediction.'], 403);
        }

        $product = $accessService->activeProductById((int) $validated['product_id']);
        if ($product === null) {
            return response()->json(['success' => false, 'message' => 'Future prediction checkout is not available right now.'], 404);
        }

        $nextPurchase = $accessService->nextPurchaseBlock($request->user(), $predictionRequest);
        if (! $nextPurchase['can_purchase']) {
            return response()->json([
                'success' => false,
                'status' => $nextPurchase['reason'] ?? 'NO_NEW_FUTURE_YEARS_AVAILABLE',
                'message' => $nextPurchase['message'] ?? 'You already have all currently supported future predictions.',
                'next_purchase' => $nextPurchase,
            ], 409);
        }

        $transaction = $paymentService->createOrder($predictionRequest, $product, $request->user(), $nextPurchase);

        return response()->json([
            'success' => true,
            'message' => 'Payment order created successfully.',
            'data' => [
                'transaction_id' => $transaction->id,
                'razorpay_order_id' => $transaction->provider_order_id,
                'razorpay_key_id' => config('services.razorpay.key_id'),
                'prefill' => $paymentService->checkoutPrefill($request->user()),
                'product' => $accessService->productPayload($product),
                'next_purchase' => $nextPurchase,
            ],
        ]);
    }

    public function verify(Request $request, RazorpayPaymentService $paymentService, FuturePredictionAccessService $accessService): JsonResponse
    {
        $validated = $request->validate([
            'razorpay_order_id' => ['required', 'string'],
            'razorpay_payment_id' => ['required', 'string'],
            'razorpay_signature' => ['required', 'string'],
        ]);

        try {
            $transaction = $paymentService->verifyCheckout(
                $validated['razorpay_order_id'],
                $validated['razorpay_payment_id'],
                $validated['razorpay_signature'],
                $request->user(),
            );
        } catch (RuntimeException) {
            return response()->json([
                'success' => false,
                'message' => 'Payment could not be verified. No future access has been activated.',
            ], 422);
        }

        $entitlement = \App\Models\PredictionEntitlement::query()
            ->where('payment_transaction_id', $transaction->id)
            ->where('prediction_type', 'future')
            ->first();

        return response()->json([
            'success' => true,
            'message' => 'Payment verified successfully.',
            'future_prediction_enabled' => $entitlement !== null,
            'entitlement' => $accessService->entitlementPayload($entitlement),
            'purchased_block' => $entitlement ? $accessService->purchasedBlockPayload($entitlement) : null,
        ]);
    }

    public function webhook(Request $request, RazorpayPaymentService $paymentService): JsonResponse
    {
        try {
            $event = $paymentService->handleWebhook(
                $request->getContent(),
                $request->header('X-Razorpay-Signature')
            );
        } catch (RuntimeException) {
            return response()->json(['success' => false, 'message' => 'Invalid webhook signature.'], 400);
        }

        return response()->json([
            'success' => true,
            'event_id' => $event->id,
            'processing_status' => $event->processing_status,
        ]);
    }
}
