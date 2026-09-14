<?php

namespace App\Http\Controllers;

use App\Models\FutureVideoManualPayment;
use App\Models\PaymentMethodSetting;
use App\Models\PaymentTransaction;
use App\Models\PredictionProduct;
use App\Models\PredictionRequest;
use App\Services\FuturePredictionAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class FutureVideoManualPaymentController extends Controller
{
    public function adminIndex(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:PENDING,VERIFIED,REJECTED,ALL'],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        $status = strtoupper((string) ($validated['status'] ?? 'PENDING'));
        $search = trim((string) ($validated['search'] ?? ''));

        $payments = FutureVideoManualPayment::query()
            ->with(['user:id,name,email', 'predictionRequest:id,request_uuid,full_name,email,submitted_at', 'product:id,name,currency,price_inclusive_tax_minor'])
            ->when($status !== 'ALL', fn ($query) => $query->where('status', $status))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner
                        ->where('purchase_reference', 'like', '%'.$search.'%')
                        ->orWhereHas('user', fn ($userQuery) => $userQuery
                            ->where('name', 'like', '%'.$search.'%')
                            ->orWhere('email', 'like', '%'.$search.'%'));
                });
            })
            ->latest('submitted_at')
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (FutureVideoManualPayment $payment): array => $this->adminPayload($payment))
            ->values();

        $counts = FutureVideoManualPayment::query()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        return response()->json([
            'success' => true,
            'data' => $payments,
            'counts' => [
                'PENDING' => (int) ($counts['PENDING'] ?? 0),
                'VERIFIED' => (int) ($counts['VERIFIED'] ?? 0),
                'REJECTED' => (int) ($counts['REJECTED'] ?? 0),
                'ALL' => (int) $counts->sum(),
            ],
        ]);
    }

    public function adminShow(FutureVideoManualPayment $payment): JsonResponse
    {
        $payment->load(['user:id,name,email', 'predictionRequest:id,request_uuid,full_name,email,submitted_at', 'product:id,name,currency,price_inclusive_tax_minor']);

        return response()->json([
            'success' => true,
            'data' => $this->adminPayload($payment),
        ]);
    }

    public function adminProof(FutureVideoManualPayment $payment)
    {
        if (! $payment->payment_proof_path || ! Storage::disk('local')->exists($payment->payment_proof_path)) {
            return response()->json([
                'success' => false,
                'message' => 'Payment proof is unavailable.',
            ], 404);
        }

        return response()->file(Storage::disk('local')->path($payment->payment_proof_path), array_filter([
            'Content-Type' => $payment->payment_proof_mime_type,
        ]));
    }

    public function adminVerify(Request $request, FutureVideoManualPayment $payment, FuturePredictionAccessService $accessService): JsonResponse
    {
        $didVerify = false;
        $verified = DB::transaction(function () use ($request, $payment, $accessService, &$didVerify): FutureVideoManualPayment {
            $lockedPayment = FutureVideoManualPayment::query()
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedPayment->status === FutureVideoManualPayment::VERIFIED) {
                return $lockedPayment->fresh(['user', 'predictionRequest', 'product']);
            }

            if ($lockedPayment->status !== FutureVideoManualPayment::PENDING) {
                throw new HttpResponseException(response()->json([
                    'success' => false,
                    'message' => 'Only pending payments can be verified.',
                ], 409));
            }

            $providerOrderId = 'manual_future_video_'.$lockedPayment->id;
            $transaction = PaymentTransaction::query()
                ->where('provider_order_id', $providerOrderId)
                ->lockForUpdate()
                ->first();

            if (! $transaction) {
                $transaction = PaymentTransaction::query()->create([
                    'prediction_request_id' => $lockedPayment->prediction_request_id,
                    'user_id' => $lockedPayment->user_id,
                    'product_id' => $lockedPayment->product_id,
                    'provider' => 'manual_gpay_qr',
                    'currency' => $lockedPayment->currency,
                    'amount_minor' => $lockedPayment->amount_minor,
                    'tax_inclusive' => true,
                    'status' => 'CAPTURED',
                    'provider_order_id' => $providerOrderId,
                    'paid_at' => now(),
                    'verified_at' => now(),
                    'captured_at' => now(),
                    'metadata' => [
                        'payment_for' => 'future_video',
                        'payment_method' => FutureVideoManualPayment::PAYMENT_METHOD,
                        'future_video_manual_payment_id' => $lockedPayment->id,
                        'purchase_reference' => $lockedPayment->purchase_reference,
                    ],
                ]);
            } else {
                $transaction->forceFill([
                    'status' => 'CAPTURED',
                    'paid_at' => $transaction->paid_at ?? now(),
                    'verified_at' => $transaction->verified_at ?? now(),
                    'captured_at' => $transaction->captured_at ?? now(),
                ])->save();
            }

            $lockedPayment->forceFill([
                'status' => FutureVideoManualPayment::VERIFIED,
                'verified_at' => now(),
                'verified_by' => $request->user()->id,
                'rejection_reason' => null,
            ])->save();

            $accessService->activate($transaction);
            $didVerify = true;

            return $lockedPayment->fresh(['user', 'predictionRequest', 'product']);
        });

        return response()->json([
            'success' => true,
            'message' => $didVerify ? 'Payment verified and Future Video enabled.' : 'Payment is already verified.',
            'data' => $this->adminPayload($verified),
        ]);
    }

    public function adminReject(Request $request, FutureVideoManualPayment $payment): JsonResponse
    {
        $validated = $request->validate([
            'rejection_reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $rejected = DB::transaction(function () use ($payment, $validated): FutureVideoManualPayment {
            $lockedPayment = FutureVideoManualPayment::query()
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedPayment->status !== FutureVideoManualPayment::PENDING) {
                throw new HttpResponseException(response()->json([
                    'success' => false,
                    'message' => 'Only pending payments can be rejected.',
                ], 409));
            }

            $lockedPayment->forceFill([
                'status' => FutureVideoManualPayment::REJECTED,
                'rejection_reason' => trim((string) ($validated['rejection_reason'] ?? '')) ?: null,
            ])->save();

            return $lockedPayment->fresh(['user', 'predictionRequest', 'product']);
        });

        return response()->json([
            'success' => true,
            'message' => 'Payment rejected.',
            'data' => $this->adminPayload($rejected),
        ]);
    }

    public function paymentSettings(): JsonResponse
    {
        $setting = PaymentMethodSetting::query()->firstOrCreate(
            ['service_type' => PaymentMethodSetting::FUTURE_VIDEO],
            ['payment_gateway_enabled' => true, 'gpay_qr_enabled' => false]
        );

        return response()->json([
            'success' => true,
            'data' => [
                'service_type' => PaymentMethodSetting::FUTURE_VIDEO,
                'payment_gateway_enabled' => (bool) $setting->payment_gateway_enabled,
                'gpay_qr_enabled' => (bool) $setting->gpay_qr_enabled,
                'qr_image_url' => $setting->qrImageUrl(),
            ],
        ]);
    }

    public function status(Request $request, FuturePredictionAccessService $accessService): JsonResponse
    {
        $validated = $request->validate([
            'prediction_request_id' => ['required', 'integer', 'exists:prediction_requests,id'],
            'request_uuid' => ['required', 'uuid'],
            'product_id' => ['nullable', 'integer', 'exists:prediction_products,id'],
        ]);

        [$predictionRequest, $product, $nextPurchase] = $this->validatedPurchaseContext($request, $validated, $accessService);
        $purchaseReference = $this->purchaseReference($request->user()->id, $predictionRequest->id, $product->id, $nextPurchase);
        $payment = FutureVideoManualPayment::query()
            ->where('purchase_reference', $purchaseReference)
            ->latest('id')
            ->first();

        return response()->json([
            'success' => true,
            'data' => $payment ? $this->payload($payment) : null,
        ]);
    }

    public function store(Request $request, FuturePredictionAccessService $accessService): JsonResponse
    {
        $validated = $request->validate([
            'prediction_request_id' => ['required', 'integer', 'exists:prediction_requests,id'],
            'request_uuid' => ['required', 'uuid'],
            'product_id' => ['required', 'integer', 'exists:prediction_products,id'],
            'payment_proof' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $setting = PaymentMethodSetting::query()->firstOrCreate(
            ['service_type' => PaymentMethodSetting::FUTURE_VIDEO],
            ['payment_gateway_enabled' => true, 'gpay_qr_enabled' => false]
        );

        if (! $setting->gpay_qr_enabled || ! $setting->qr_image_path) {
            return response()->json([
                'success' => false,
                'message' => 'GPay QR payment is not available right now.',
            ], 409);
        }

        [$predictionRequest, $product, $nextPurchase] = $this->validatedPurchaseContext($request, $validated, $accessService);
        if (! $nextPurchase['can_purchase']) {
            return response()->json([
                'success' => false,
                'status' => $nextPurchase['reason'] ?? 'NO_NEW_FUTURE_YEARS_AVAILABLE',
                'message' => $nextPurchase['message'] ?? 'You already have all currently supported future predictions.',
                'next_purchase' => $nextPurchase,
            ], 409);
        }

        $file = $validated['payment_proof'];
        $newProofPath = $file->store('future-video-manual-payments', 'local');
        $purchaseReference = $this->purchaseReference($request->user()->id, $predictionRequest->id, $product->id, $nextPurchase);

        $payment = DB::transaction(function () use ($request, $predictionRequest, $product, $nextPurchase, $validated, $file, $newProofPath, $purchaseReference): FutureVideoManualPayment {
            $existing = FutureVideoManualPayment::query()
                ->where('purchase_reference', $purchaseReference)
                ->lockForUpdate()
                ->first();

            if ($existing && $existing->status === FutureVideoManualPayment::PENDING) {
                Storage::disk('local')->delete($newProofPath);
                throw new HttpResponseException(response()->json([
                    'success' => false,
                    'message' => 'Your payment proof is already awaiting verification.',
                    'data' => $this->payload($existing),
                ], 409));
            }

            if ($existing && $existing->status === FutureVideoManualPayment::VERIFIED) {
                Storage::disk('local')->delete($newProofPath);
                throw new HttpResponseException(response()->json([
                    'success' => false,
                    'message' => 'This payment has already been verified.',
                    'data' => $this->payload($existing),
                ], 409));
            }

            if ($existing) {
                $oldProofPath = $existing->payment_proof_path;
                $existing->forceFill($this->paymentData($request, $predictionRequest, $product, $nextPurchase, $validated, $file, $newProofPath, $purchaseReference))->save();
                if ($oldProofPath && $oldProofPath !== $newProofPath) {
                    Storage::disk('local')->delete($oldProofPath);
                }

                return $existing->refresh();
            }

            return FutureVideoManualPayment::query()->create(
                $this->paymentData($request, $predictionRequest, $product, $nextPurchase, $validated, $file, $newProofPath, $purchaseReference)
            );
        });

        return response()->json([
            'success' => true,
            'message' => 'Payment submitted successfully. Your payment is awaiting verification.',
            'data' => $this->payload($payment),
        ], 201);
    }

    private function validatedPurchaseContext(Request $request, array $validated, FuturePredictionAccessService $accessService): array
    {
        $predictionRequest = PredictionRequest::query()->findOrFail($validated['prediction_request_id']);

        if ($predictionRequest->email !== null && ! hash_equals($predictionRequest->email, $request->user()->email)) {
            throw new HttpResponseException(response()->json(['success' => false, 'message' => 'You are not authorised to purchase this prediction.'], 403));
        }

        if (! hash_equals($predictionRequest->request_uuid, $validated['request_uuid'])) {
            throw new HttpResponseException(response()->json(['success' => false, 'message' => 'You are not authorised to purchase this prediction.'], 403));
        }

        $productId = (int) ($validated['product_id'] ?? 0);
        $product = $productId > 0
            ? $accessService->activeProductById($productId)
            : $accessService->activeProduct();

        if ($product === null) {
            throw new HttpResponseException(response()->json(['success' => false, 'message' => 'Future prediction checkout is not available right now.'], 404));
        }

        return [$predictionRequest, $product, $accessService->nextPurchaseBlock($request->user(), $predictionRequest)];
    }

    private function paymentData(Request $request, PredictionRequest $predictionRequest, PredictionProduct $product, array $nextPurchase, array $validated, object $file, string $proofPath, string $purchaseReference): array
    {
        return [
            'user_id' => $request->user()->id,
            'prediction_request_id' => $predictionRequest->id,
            'product_id' => $product->id,
            'service_type' => FutureVideoManualPayment::SERVICE_TYPE,
            'purchase_reference' => $purchaseReference,
            'amount_minor' => (int) $product->price_inclusive_tax_minor,
            'currency' => $product->currency ?: 'INR',
            'payment_method' => FutureVideoManualPayment::PAYMENT_METHOD,
            'payment_proof_path' => $proofPath,
            'payment_proof_original_name' => $file->getClientOriginalName(),
            'payment_proof_mime_type' => $file->getMimeType(),
            'payment_proof_size' => $file->getSize(),
            'status' => FutureVideoManualPayment::PENDING,
            'submitted_at' => now(),
            'verified_at' => null,
            'verified_by' => null,
            'rejection_reason' => null,
            'metadata' => [
                'next_purchase' => $nextPurchase,
            ],
        ];
    }

    private function purchaseReference(int $userId, int $predictionRequestId, int $productId, array $nextPurchase): string
    {
        $period = implode('-', [
            $nextPurchase['next_start_year'] ?? 'na',
            $nextPurchase['next_end_year'] ?? 'na',
            $nextPurchase['next_start_age'] ?? 'na',
            $nextPurchase['next_end_age'] ?? 'na',
        ]);

        return 'future_video_'.$userId.'_'.$predictionRequestId.'_'.$productId.'_'.sha1($period);
    }

    private function payload(FutureVideoManualPayment $payment): array
    {
        return [
            'id' => $payment->id,
            'service_type' => $payment->service_type,
            'purchase_reference' => $payment->purchase_reference,
            'amount_minor' => $payment->amount_minor,
            'currency' => $payment->currency,
            'payment_method' => $payment->payment_method,
            'status' => $payment->status,
            'status_label' => ucwords(strtolower(str_replace('_', ' ', $payment->status))),
            'submitted_at' => $payment->submitted_at?->toJSON(),
            'verified_at' => $payment->verified_at?->toJSON(),
            'rejection_reason' => $payment->rejection_reason,
        ];
    }

    private function adminPayload(FutureVideoManualPayment $payment): array
    {
        $payload = $this->payload($payment);

        return array_merge($payload, [
            'payment_method_label' => 'GPay QR',
            'amount_label' => $this->displayAmount((int) $payment->amount_minor, $payment->currency),
            'payment_proof' => [
                'file_name' => $payment->payment_proof_original_name,
                'mime_type' => $payment->payment_proof_mime_type,
                'file_size' => $payment->payment_proof_size,
                'url' => url('/api/admin/future-video-manual-payments/'.$payment->id.'/proof'),
            ],
            'user' => [
                'id' => $payment->user?->id,
                'name' => $payment->user?->name,
                'email' => $payment->user?->email,
            ],
            'prediction_request' => [
                'id' => $payment->predictionRequest?->id,
                'request_uuid' => $payment->predictionRequest?->request_uuid,
                'full_name' => $payment->predictionRequest?->full_name,
                'email' => $payment->predictionRequest?->email,
                'submitted_at' => $payment->predictionRequest?->submitted_at?->toJSON(),
            ],
            'product' => [
                'id' => $payment->product?->id,
                'name' => $payment->product?->name,
            ],
        ]);
    }

    private function displayAmount(int $amountMinor, string $currency): string
    {
        $amount = number_format($amountMinor / 100, 0);

        return strtoupper($currency) === 'INR' ? 'Rs '.$amount : strtoupper($currency).' '.$amount;
    }
}
