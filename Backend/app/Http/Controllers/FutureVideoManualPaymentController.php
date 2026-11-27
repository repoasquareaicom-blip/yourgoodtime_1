<?php

namespace App\Http\Controllers;

use App\Models\FutureVideoManualPayment;
use App\Models\PaymentMethodSetting;
use App\Models\PaymentTransaction;
use App\Models\PredictionEntitlement;
use App\Models\PredictionRequest;
use App\Models\PredictionResult;
use App\Models\User;
use App\Services\FuturePredictionAccessService;
use App\Services\Prediction\PredictionRegenerationService;
use App\Services\Prediction\PredictionTimelineSummaryService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class FutureVideoManualPaymentController extends Controller
{
    public function adminIndex(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:AWAITING_SUBMISSION,PENDING,VERIFIED,REJECTED,ALL'],
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
                'AWAITING_SUBMISSION' => (int) ($counts['AWAITING_SUBMISSION'] ?? 0),
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

    public function adminUsers(Request $request): JsonResponse
    {
        $validated = $request->validate(['search' => ['required', 'string', 'min:2', 'max:120']]);
        $term = '%'.trim($validated['search']).'%';
        $phone = preg_replace('/\D/', '', $validated['search']);
        $users = User::query()
            ->where(function ($query) use ($term, $phone): void {
                $query->where('name', 'like', $term)->orWhere('email', 'like', $term);
                if (strlen($phone) >= 2) {
                    $query->orWhere('phone_number', 'like', '%'.$phone.'%')
                        ->orWhere('phone_e164', 'like', '%'.$phone.'%');
                }
            })
            ->orderBy('name')->limit(20)->get(['id', 'name', 'email', 'phone_number', 'phone_e164']);

        return response()->json(['data' => $users]);
    }

    public function adminOptions(User $user, FuturePredictionAccessService $accessService, PredictionTimelineSummaryService $timelineService): JsonResponse
    {
        $profile = PredictionRequest::query()->completedBirthProfile($user->email)->first();

        return response()->json(['data' => [
            'profile' => $profile ? [
                'full_name' => $profile->full_name,
                'date_of_birth' => $profile->date_of_birth->toDateString(),
                'time_of_birth' => $profile->time_of_birth,
                'place_of_birth' => $profile->place_of_birth,
                'timezone' => $profile->timezone,
                'gender' => $profile->gender,
                'marriage_status' => $profile->marriage_status,
                'astrology' => data_get($profile->normalized_astrology_data, 'identity', []),
            ] : null,
            'periods' => $profile ? $this->manualPeriods($user, $profile, $accessService, $timelineService) : [],
        ]]);
    }

    public function adminCreate(Request $request, FuturePredictionAccessService $accessService, PredictionTimelineSummaryService $timelineService): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'prediction_type' => ['required', 'in:past,future'],
            'period_reference' => ['required', 'string', 'max:160'],
            'payment_method' => ['required', 'in:GPAY_QR,BANK_TRANSFER,PAYMENT_GATEWAY,CASH,OTHER'],
            'amount' => ['required', 'decimal:0,2', 'gt:0', 'max:42949672.95'],
            'payment_received_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'transaction_reference' => ['nullable', 'string', 'max:120'],
            'payment_proof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'admin_remarks' => ['nullable', 'string', 'max:2000'],
            'payment_confirmed' => ['required', 'accepted'],
        ]);

        $file = $validated['payment_proof'] ?? null;
        $path = $this->storeProof($file);
        $oldProofPath = null;
        try {
            $payment = DB::transaction(function () use ($request, $validated, $file, $path, $accessService, $timelineService, &$oldProofPath): FutureVideoManualPayment {
                $user = User::query()->lockForUpdate()->findOrFail($validated['user_id']);
                $profile = PredictionRequest::query()->completedBirthProfile($user->email)->first();
                abort_if($profile === null, 422, 'The user needs saved birth and astrology details.');
                $type = $validated['prediction_type'];
                $period = $this->manualPeriods($user, $profile, $accessService, $timelineService)[$type] ?? null;
                abort_unless($period && $period['reference'] === $validated['period_reference'], 409, 'This period is no longer available. Refresh the user details.');

                $payment = FutureVideoManualPayment::query()->where('purchase_reference', $period['reference'])->lockForUpdate()->first();
                abort_if($payment?->status === FutureVideoManualPayment::VERIFIED, 409, 'This prediction payment has already been verified.');
                $payment ??= new FutureVideoManualPayment();

                if ($type === 'past') {
                    $existingResult = $profile->results()->where('prediction_type', 'past')->where('status', 'completed')->first();
                    abort_if($existingResult?->hasReadingVideos(), 409, 'This Past Prediction is already available.');
                } else {
                    $owned = PredictionEntitlement::query()->where('user_id', $user->id)
                        ->where('prediction_type', 'future')->whereIn('status', ['ACTIVE', 'EXPIRED'])
                        ->where('access_start_year', '<=', $period['range']['next_end_year'])
                        ->where('access_end_year', '>=', $period['range']['next_start_year'])->exists();
                    abort_if($owned, 409, 'This Future Prediction period is already owned.');
                }

                $reference = mb_strtoupper(trim($validated['transaction_reference'] ?? '')) ?: null;
                if ($reference && FutureVideoManualPayment::query()->where('transaction_reference', $reference)->exists()) {
                    throw ValidationException::withMessages(['transaction_reference' => 'This payment reference has already been recorded.']);
                }

                if ($file) {
                    $oldProofPath = $payment->payment_proof_path;
                    $payment->forceFill($this->proofData($file, $path));
                }
                $payment->forceFill([
                    'user_id' => $user->id,
                    'prediction_request_id' => $period['prediction_request_id'],
                    'product_id' => $period['product_id'],
                    'service_type' => $type === 'past' ? FutureVideoManualPayment::PAST_SERVICE_TYPE : FutureVideoManualPayment::SERVICE_TYPE,
                    'purchase_reference' => $period['reference'],
                    'amount_minor' => (int) round((float) $validated['amount'] * 100),
                    'currency' => $period['currency'],
                    'payment_method' => $validated['payment_method'],
                    'submitted_at' => $payment->submitted_at ?? now(),
                    'transaction_reference' => $reference,
                ]);

                return $this->verify($payment, $request->user()->id, [
                    'source' => 'manual_admin',
                    'period' => $period['range'],
                    'next_purchase' => $type === 'future' ? $period['range'] : null,
                    'payment_received_on' => $validated['payment_received_on'],
                    'admin_remarks' => $validated['admin_remarks'] ?? null,
                ]);
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            $this->deleteProof($path);
            throw ValidationException::withMessages(['transaction_reference' => 'This payment or transaction reference has already been recorded.']);
        } catch (Throwable $exception) {
            $this->deleteProof($path);
            throw $exception;
        }

        $this->deleteProof($oldProofPath);

        return $this->activationResponse($payment);
    }

    private function manualPeriods(User $user, PredictionRequest $profile, FuturePredictionAccessService $accessService, PredictionTimelineSummaryService $timelineService): array
    {
        $periods = [];
        $now = CarbonImmutable::now(config('services.prediction_billing.business_timezone', 'Asia/Kolkata'));
        $past = $timelineService->pastRange(CarbonImmutable::instance($profile->date_of_birth), $now);
        if ($past['available']) {
            $periods['past'] = [
                'reference' => 'past_prediction_'.$user->id.'_'.$past['start_year'].'_'.$past['end_year'],
                'prediction_request_id' => $profile->id,
                'product_id' => null,
                'range' => $past,
                'currency' => 'INR',
                'amount_minor' => null,
            ];
        }

        $incomplete = $this->activeQrPayments($user->id)->first();
        $future = data_get($incomplete?->metadata, 'next_purchase') ?? $accessService->nextPurchaseBlock($user, $profile);
        $product = $incomplete?->product ?? $accessService->activeProduct();

        if ($product && $future['can_purchase']) {
            $periods['future'] = [
                'reference' => $incomplete?->purchase_reference ?? $this->purchaseReference($user->id, $profile->id, $product->id, $future),
                'prediction_request_id' => $incomplete?->prediction_request_id ?? $profile->id,
                'product_id' => $product->id,
                'range' => $future,
                'currency' => $incomplete?->currency ?? ($product->currency ?: 'INR'),
                'amount_minor' => $incomplete?->amount_minor ?? $product->price_inclusive_tax_minor,
                'existing_status' => $incomplete?->status,
            ];
        }

        return $periods;
    }

    private function activeQrPayments(int $userId): Builder
    {
        return FutureVideoManualPayment::query()
            ->where('user_id', $userId)
            ->where('service_type', FutureVideoManualPayment::SERVICE_TYPE)
            ->whereIn('status', [FutureVideoManualPayment::AWAITING, FutureVideoManualPayment::PENDING])
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [FutureVideoManualPayment::PENDING])
            ->oldest('id');
    }

    public function adminVerify(Request $request, FutureVideoManualPayment $payment): JsonResponse
    {
        $validated = $request->validate([
            'payment_confirmed' => ['accepted', 'required'],
            'admin_remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $payment = DB::transaction(function () use ($request, $payment, $validated): FutureVideoManualPayment {
            $payment = FutureVideoManualPayment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($payment->status === FutureVideoManualPayment::VERIFIED) {
                return $payment;
            }
            abort_unless($payment->status === FutureVideoManualPayment::PENDING, 409, 'Only pending payments can be verified.');

            return $this->verify($payment, $request->user()->id, [
                'admin_remarks' => $validated['admin_remarks'] ?? null,
            ]);
        }, 3);

        return $this->activationResponse($payment);
    }

    public function adminRetry(FutureVideoManualPayment $payment): JsonResponse
    {
        abort_unless($payment->status === FutureVideoManualPayment::VERIFIED, 409, 'Verify payment before retrying activation.');

        return $this->activationResponse($payment);
    }

    private function verify(FutureVideoManualPayment $payment, int $adminId, array $details): FutureVideoManualPayment
    {
        $metadata = array_merge($payment->metadata ?? [], $details, ['activation_pending' => true]);
        $payment->forceFill([
            'status' => FutureVideoManualPayment::VERIFIED,
            'verified_at' => now(),
            'verified_by' => $adminId,
            'rejection_reason' => null,
            'metadata' => $metadata,
        ])->save();

        if ($payment->service_type === FutureVideoManualPayment::SERVICE_TYPE) {
            $receivedAt = $metadata['payment_received_on'] ?? now();
            PaymentTransaction::query()->firstOrCreate(
                ['provider_order_id' => 'manual_future_video_'.$payment->id],
                [
                    'prediction_request_id' => $payment->prediction_request_id,
                    'user_id' => $payment->user_id,
                    'product_id' => $payment->product_id,
                    'provider' => $payment->payment_method === FutureVideoManualPayment::PAYMENT_METHOD ? 'manual_gpay_qr' : 'manual_prediction',
                    'currency' => $payment->currency,
                    'amount_minor' => $payment->amount_minor,
                    'tax_inclusive' => true,
                    'status' => 'CAPTURED',
                    'paid_at' => $receivedAt,
                    'verified_at' => now(),
                    'captured_at' => now(),
                    'metadata' => [
                        'payment_for' => 'future_video',
                        'payment_method' => $payment->payment_method,
                        'future_video_manual_payment_id' => $payment->id,
                        'purchase_reference' => $payment->purchase_reference,
                    ],
                ]
            );
        }

        return $payment;
    }

    private function activationResponse(FutureVideoManualPayment $payment): JsonResponse
    {
        try {
            if ($this->activationRequiresWork($payment)) {
                $this->activatePrediction($payment);
            }
            $message = 'Payment verified and prediction ready to watch.';
        } catch (Throwable $exception) {
            report($exception);
            $message = 'Payment is verified. Prediction preparation is incomplete. Retry activation from Payment Verification.';
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $this->adminPayload($payment->fresh(['user', 'predictionRequest', 'product'])),
        ]);
    }

    private function activationRequiresWork(FutureVideoManualPayment $payment): bool
    {
        if ($payment->status !== FutureVideoManualPayment::VERIFIED) {
            return false;
        }

        if (data_get($payment->metadata, 'activation_pending')) {
            return true;
        }

        if ($payment->service_type !== FutureVideoManualPayment::SERVICE_TYPE) {
            return false;
        }

        $result = PredictionResult::query()
            ->select('prediction_results.*')
            ->join('prediction_entitlements', 'prediction_entitlements.id', '=', 'prediction_results.prediction_entitlement_id')
            ->join('payment_transactions', 'payment_transactions.id', '=', 'prediction_entitlements.payment_transaction_id')
            ->where('payment_transactions.provider_order_id', 'manual_future_video_'.$payment->id)
            ->where('prediction_results.prediction_type', 'future')
            ->where('prediction_results.status', 'completed')
            ->first();

        return ! $result?->hasReadingVideos();
    }

    private function activatePrediction(FutureVideoManualPayment $payment): void
    {
        $isFuture = $payment->service_type === FutureVideoManualPayment::SERVICE_TYPE;
        $entitlement = null;
        if ($isFuture) {
            $transaction = PaymentTransaction::query()
                ->where('provider_order_id', 'manual_future_video_'.$payment->id)->firstOrFail();
            $entitlement = app(FuturePredictionAccessService::class)->activate(
                $transaction,
                data_get($payment->metadata, 'next_purchase')
            );
        }

        if ($isFuture || data_get($payment->metadata, 'source') === 'manual_admin') {
            $generator = app(PredictionRegenerationService::class);
            $type = $isFuture ? 'future' : 'past';
            $result = $generator->regenerate($payment->predictionRequest, $type, $entitlement);
            if (! $result->hasReadingVideos() && ! $result->wasRecentlyCreated) {
                $result = $generator->regenerate($payment->predictionRequest, $type, $entitlement, force: true);
            }
            if (! $result->hasReadingVideos()) {
                throw new \RuntimeException('Prediction videos are not ready.');
            }
            if ($entitlement !== null && $entitlement->generated_prediction_result_id === null) {
                $entitlement->forceFill(['generated_prediction_result_id' => $result->id])->save();
            }
        }

        $payment->forceFill([
            'metadata' => array_merge($payment->metadata ?? [], ['activation_pending' => false]),
        ])->save();
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

    public function downloadQr()
    {
        $setting = PaymentMethodSetting::query()->where('service_type', PaymentMethodSetting::FUTURE_VIDEO)->first();
        $path = (string) $setting?->qr_image_path;
        abort_unless($setting?->gpay_qr_enabled && preg_match('~\Apayment-method-qr/(?:[\w-]+/)*[\w.-]+\.(?:jpe?g|png|webp)\z~i', $path), 404);

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $filename = 'your-good-time-upi-qr.'.$extension;
        $contentType = match ($extension) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
        $disk = Storage::disk('public');
        if ($disk->exists($path)) {
            return $disk->download($path, $filename, ['Content-Type' => $contentType]);
        }

        // Development may use the published QR without a copy in its local public disk.
        $publishedUrl = $disk->url($path);
        abort_unless($publishedUrl === 'https://api.yourgoodtime.in/storage/'.$path, 404);
        try {
            $image = Http::withoutRedirecting()->timeout(5)->get($publishedUrl);
        } catch (ConnectionException) {
            abort(502, 'The configured QR image is unavailable.');
        }
        abort_unless($image->ok() && $image->header('Content-Type') === $contentType, 502, 'The configured QR image is unavailable.');

        return response()->streamDownload(fn () => print($image->body()), $filename, ['Content-Type' => $contentType]);
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
        $payment = $this->activeQrPayments($request->user()->id)->first() ?? FutureVideoManualPayment::query()
            ->where('purchase_reference', $purchaseReference)
            ->latest('id')
            ->first();

        return response()->json([
            'success' => true,
            'data' => $payment ? $this->payload($payment) : null,
        ]);
    }

    public function begin(Request $request, FuturePredictionAccessService $accessService): JsonResponse
    {
        return $this->saveQrPayment($request, $accessService, false);
    }

    public function store(Request $request, FuturePredictionAccessService $accessService): JsonResponse
    {
        return $this->saveQrPayment($request, $accessService, true);
    }

    private function saveQrPayment(Request $request, FuturePredictionAccessService $accessService, bool $submit): JsonResponse
    {
        $validated = $request->validate([
            'payment_id' => ['nullable', 'integer'],
            'prediction_request_id' => ['required_without:payment_id', 'integer', 'exists:prediction_requests,id'],
            'request_uuid' => ['required_without:payment_id', 'uuid'],
            'product_id' => ['required_without:payment_id', 'integer', 'exists:prediction_products,id'],
            'payment_proof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $file = $submit ? ($validated['payment_proof'] ?? null) : null;
        $newProofPath = $this->storeProof($file);
        $oldProofPath = null;
        $didSubmit = false;

        try {
            $payment = DB::transaction(function () use ($request, $accessService, $validated, $submit, $file, $newProofPath, &$oldProofPath, &$didSubmit) {
                $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
                $didSubmit = false;
                if (isset($validated['payment_id'])) {
                    $payment = FutureVideoManualPayment::query()
                        ->where('user_id', $user->id)
                        ->where('service_type', FutureVideoManualPayment::SERVICE_TYPE)
                        ->lockForUpdate()
                        ->findOrFail($validated['payment_id']);
                    abort_if($payment->status === FutureVideoManualPayment::REJECTED, 409, 'Restart this rejected payment from checkout.');
                } else {
                    [$predictionRequest, $product, $nextPurchase] = $this->validatedPurchaseContext($request, $validated, $accessService);
                    $reference = $this->purchaseReference($user->id, $predictionRequest->id, $product->id, $nextPurchase);
                    $payment = $this->activeQrPayments($user->id)->lockForUpdate()->first()
                        ?? FutureVideoManualPayment::query()->where('purchase_reference', $reference)->lockForUpdate()->first();

                    if (! $payment || $payment->status === FutureVideoManualPayment::REJECTED) {
                        abort_unless($nextPurchase['can_purchase'], 409, $nextPurchase['message'] ?? 'No new future years are available.');
                        $setting = PaymentMethodSetting::query()->where('service_type', PaymentMethodSetting::FUTURE_VIDEO)->first();
                        abort_unless($setting?->gpay_qr_enabled && $setting->qr_image_path, 409, 'GPay QR payment is not available right now.');

                        $payment ??= new FutureVideoManualPayment();
                        $oldProofPath = $payment->payment_proof_path;
                        $proofColumn = collect(Schema::getColumns($payment->getTable()))->firstWhere('name', 'payment_proof_path');
                        // Older installations require a non-null path even before a screenshot is uploaded.
                        $emptyProofPath = ($proofColumn['nullable'] ?? true) ? null : '';
                        $payment->forceFill(array_merge($this->proofData(null, $emptyProofPath), [
                            'user_id' => $user->id,
                            'prediction_request_id' => $predictionRequest->id,
                            'product_id' => $product->id,
                            'service_type' => FutureVideoManualPayment::SERVICE_TYPE,
                            'purchase_reference' => $reference,
                            'amount_minor' => (int) $product->price_inclusive_tax_minor,
                            'currency' => $product->currency ?: 'INR',
                            'payment_method' => FutureVideoManualPayment::PAYMENT_METHOD,
                            'status' => FutureVideoManualPayment::AWAITING,
                            'submitted_at' => null,
                            'verified_at' => null,
                            'verified_by' => null,
                            'rejection_reason' => null,
                            'metadata' => ['next_purchase' => $nextPurchase],
                        ]))->save();
                    }
                }

                if ($submit && $payment->status === FutureVideoManualPayment::AWAITING) {
                    $payment->forceFill(array_merge($this->proofData($file, $newProofPath ?? $payment->payment_proof_path), [
                        'status' => FutureVideoManualPayment::PENDING,
                        'submitted_at' => now(),
                    ]))->save();
                    $didSubmit = true;
                }

                return $payment;
            }, 3);
        } catch (Throwable $exception) {
            $this->deleteProof($newProofPath);
            throw $exception;
        }

        if ($newProofPath !== $payment->payment_proof_path) {
            $this->deleteProof($newProofPath);
        }
        if ($oldProofPath !== $payment->payment_proof_path) {
            $this->deleteProof($oldProofPath);
        }

        return response()->json(['success' => true, 'data' => $this->payload($payment)], $didSubmit ? 201 : 200);
    }

    private function validatedPurchaseContext(Request $request, array $validated, FuturePredictionAccessService $accessService): array
    {
        $predictionRequest = PredictionRequest::query()->findOrFail($validated['prediction_request_id']);
        abort_unless(
            $predictionRequest->email !== null
            && strcasecmp(trim($predictionRequest->email), trim($request->user()->email)) === 0
            && hash_equals($predictionRequest->request_uuid, $validated['request_uuid']),
            403,
            'You are not authorised to purchase this prediction.'
        );

        $productId = (int) ($validated['product_id'] ?? 0);
        $product = $productId > 0 ? $accessService->activeProductById($productId) : $accessService->activeProduct();
        abort_if($product === null, 404, 'Future prediction checkout is not available right now.');

        return [$predictionRequest, $product, $accessService->nextPurchaseBlock($request->user(), $predictionRequest)];
    }

    private function storeProof(?UploadedFile $file): ?string
    {
        if ($file === null) {
            return null;
        }

        $path = $file->store('future-video-manual-payments', 'local');
        abort_if($path === false, 500, 'Unable to save the payment screenshot.');

        return $path;
    }

    private function deleteProof(?string $path): void
    {
        if ($path) {
            Storage::disk('local')->delete($path);
        }
    }

    private function proofData(?UploadedFile $file, ?string $path): array
    {
        return [
            'payment_proof_path' => $path,
            'payment_proof_original_name' => $file?->getClientOriginalName(),
            'payment_proof_mime_type' => $file?->getMimeType(),
            'payment_proof_size' => $file?->getSize(),
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
            'product_name' => $payment->product?->name,
            'status' => $payment->status,
            'period' => data_get($payment->metadata, 'next_purchase') ?? data_get($payment->metadata, 'period'),
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
            'payment_method_label' => match ($payment->payment_method) {
                'GPAY_QR' => 'GPay QR / UPI',
                'BANK_TRANSFER' => 'Bank Transfer',
                'PAYMENT_GATEWAY' => 'Online Payment',
                default => ucwords(strtolower($payment->payment_method)),
            },
            'prediction_type' => $payment->service_type === FutureVideoManualPayment::PAST_SERVICE_TYPE ? 'past' : 'future',
            'manual_entry' => data_get($payment->metadata, 'source') === 'manual_admin',
            'activation_pending' => $this->activationRequiresWork($payment),
            'admin_remarks' => data_get($payment->metadata, 'admin_remarks'),
            'payment_received_on' => data_get($payment->metadata, 'payment_received_on'),
            'transaction_reference' => $payment->transaction_reference,
            'amount_label' => $this->displayAmount((int) $payment->amount_minor, $payment->currency),
            'payment_proof' => $payment->payment_proof_path ? [
                'file_name' => $payment->payment_proof_original_name,
                'mime_type' => $payment->payment_proof_mime_type,
                'file_size' => $payment->payment_proof_size,
                'url' => url('/api/admin/future-video-manual-payments/'.$payment->id.'/proof'),
            ] : null,
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
        $amount = number_format($amountMinor / 100, $amountMinor % 100 === 0 ? 0 : 2);

        return strtoupper($currency) === 'INR' ? 'Rs '.$amount : strtoupper($currency).' '.$amount;
    }
}
