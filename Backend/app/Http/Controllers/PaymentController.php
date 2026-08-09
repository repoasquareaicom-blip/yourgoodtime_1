<?php

namespace App\Http\Controllers;

use App\Models\AstrologerAppointment;
use App\Models\AppointmentPaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\PredictionRequest;
use App\Models\TempleRemedyRequest;
use App\Services\FuturePredictionAccessService;
use App\Services\RazorpayPaymentService;
use App\Services\AstrologerAppointmentService;
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

    public function createTempleRemedyOrder(Request $request, RazorpayPaymentService $paymentService): JsonResponse
    {
        $validated = $request->validate([
            'temple_remedy_request_id' => ['required', 'integer', 'exists:temple_remedy_requests,id'],
        ]);

        $templeRemedy = TempleRemedyRequest::query()->findOrFail($validated['temple_remedy_request_id']);

        if ($templeRemedy->user_id !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'You are not authorised to pay for this Temple Remedy request.'], 403);
        }

        if ($templeRemedy->status !== 'PAYMENT_PENDING') {
            return response()->json(['success' => false, 'message' => 'This remedy is not awaiting payment.'], 409);
        }

        if (! $templeRemedy->amount_minor || $templeRemedy->amount_minor <= 0) {
            return response()->json(['success' => false, 'message' => 'Unable to start payment. Please try again.'], 409);
        }

        if ($templeRemedy->payment_status === 'PAID') {
            return response()->json(['success' => false, 'message' => 'Payment is already completed for this Temple Remedy request.'], 409);
        }

        $transaction = $paymentService->createTempleRemedyOrder($templeRemedy, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Temple Remedy payment order created successfully.',
            'data' => [
                'transaction_id' => $transaction->id,
                'razorpay_order_id' => $transaction->provider_order_id,
                'razorpay_key_id' => config('services.razorpay.key_id'),
                'prefill' => $paymentService->checkoutPrefill($request->user()),
                'remedy' => [
                    'id' => $templeRemedy->id,
                    'name' => 'Temple Remedy',
                    'amount_minor' => $transaction->amount_minor,
                    'currency' => $transaction->currency,
                ],
            ],
        ]);
    }

    public function createAppointmentOrder(Request $request, RazorpayPaymentService $paymentService, AstrologerAppointmentService $appointmentService): JsonResponse
    {
        $validated = $request->validate([
            'appointment_id' => ['required', 'integer', 'exists:astrologer_appointments,id'],
        ]);

        $appointment = AstrologerAppointment::query()->findOrFail($validated['appointment_id']);

        if ($appointment->user_id !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'You are not authorised to pay for this appointment.'], 403);
        }

        if (in_array($appointment->status, ['completed', 'cancelled'], true)) {
            return response()->json(['success' => false, 'message' => 'This appointment is not awaiting payment.'], 409);
        }

        if ($appointment->payment_status === 'PAID') {
            return response()->json(['success' => false, 'message' => 'Payment is already completed for this appointment.'], 409);
        }

        if ($appointment->payment_status === 'FAILED') {
            return response()->json(['success' => false, 'message' => 'Payment failed for this appointment. Please create a new appointment request.'], 409);
        }

        if ($appointmentService->appointmentPaymentMethod($appointment) !== AppointmentPaymentMethod::PAYMENT_GATEWAY) {
            return response()->json(['success' => false, 'message' => 'This appointment is configured for QR / Online payment.'], 409);
        }

        $transaction = $paymentService->createAppointmentOrder($appointment, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Appointment payment order created successfully.',
            'data' => [
                'transaction_id' => $transaction->id,
                'razorpay_order_id' => $transaction->provider_order_id,
                'razorpay_key_id' => config('services.razorpay.key_id'),
                'prefill' => $paymentService->checkoutPrefill($request->user()),
                'appointment' => [
                    'id' => $appointment->id,
                    'appointment_number' => $appointment->appointment_number,
                    'amount_minor' => $transaction->amount_minor,
                    'currency' => $transaction->currency,
                ],
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
                'message' => 'Payment could not be verified. No paid access has been activated.',
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
            'temple_remedy_paid' => $transaction->temple_remedy_request_id !== null,
            'temple_remedy_request_id' => $transaction->temple_remedy_request_id,
            'appointment_paid' => $transaction->astrologer_appointment_id !== null,
            'appointment_id' => $transaction->astrologer_appointment_id,
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
