<?php

namespace App\Services;

use App\Models\PaymentEvent;
use App\Models\PaymentTransaction;
use App\Models\PredictionProduct;
use App\Models\PredictionRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class RazorpayPaymentService
{
    public function __construct(private readonly FuturePredictionAccessService $accessService)
    {
    }

    public function createOrder(PredictionRequest $predictionRequest, PredictionProduct $product, User $user, ?array $nextPurchase = null): PaymentTransaction
    {
        return DB::transaction(function () use ($predictionRequest, $product, $user, $nextPurchase): PaymentTransaction {
            $transaction = PaymentTransaction::query()->create([
                'prediction_request_id' => $predictionRequest->id,
                'user_id' => $user->id,
                'product_id' => $product->id,
                'provider' => 'razorpay',
                'currency' => $product->currency,
                'amount_minor' => $product->price_inclusive_tax_minor,
                'tax_inclusive' => $product->tax_inclusive,
                'status' => 'PENDING',
                'metadata' => [
                    'product_code' => $product->code,
                    'proposed_future_period' => $nextPurchase,
                ],
            ]);

            $providerOrderId = $this->createProviderOrder($transaction, $product);
            $transaction->forceFill(['provider_order_id' => $providerOrderId])->save();

            return $transaction;
        });
    }

    public function verifyCheckout(string $orderId, string $paymentId, string $signature, User $user): PaymentTransaction
    {
        if (! $this->validPaymentSignature($orderId, $paymentId, $signature)) {
            $this->recordEvent(null, 'checkout.verification_failed', [
                'razorpay_order_id' => $orderId,
                'razorpay_payment_id' => $paymentId,
            ], false, 'FAILED', 'Invalid Razorpay signature.');

            throw new RuntimeException('Invalid Razorpay signature.');
        }

        $paymentEntity = $this->fetchPaymentEntity($paymentId);

        return DB::transaction(function () use ($orderId, $paymentId, $signature, $user, $paymentEntity): PaymentTransaction {
            $transaction = PaymentTransaction::query()
                ->where('provider_order_id', $orderId)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($transaction === null) {
                throw new RuntimeException('Payment transaction not found for authenticated user.');
            }

            if ($transaction->status === 'CAPTURED' && $transaction->provider_payment_id === $paymentId) {
                return $transaction;
            }

            $this->applyPaymentEntity($transaction, $paymentEntity);

            $capturedAt = $this->paymentCapturedAt($paymentEntity);
            $transaction->forceFill([
                'provider_payment_id' => $paymentId,
                'provider_signature' => $signature,
                'status' => 'CAPTURED',
                'paid_at' => $transaction->paid_at ?? now(),
                'verified_at' => now(),
                'captured_at' => $transaction->captured_at ?? $capturedAt,
            ])->save();

            $this->updateUserContactFromPayment($user, $paymentEntity, $transaction);

            $this->recordEvent($transaction, 'checkout.verified', [
                'razorpay_order_id' => $orderId,
                'razorpay_payment_id' => $paymentId,
            ], true, 'PROCESSED');

            $this->accessService->activate($transaction);

            return $transaction->refresh();
        });
    }

    public function handleWebhook(string $rawBody, ?string $signature): PaymentEvent
    {
        $verified = $signature !== null && $this->validWebhookSignature($rawBody, $signature);
        $payload = json_decode($rawBody, true) ?: [];
        $eventType = (string) ($payload['event'] ?? 'unknown');
        $eventId = $payload['id'] ?? null;
        $eventHash = hash('sha256', $eventId ? 'razorpay|'.$eventId : $rawBody);
        $transaction = $this->transactionFromWebhook($payload);

        $event = PaymentEvent::query()->firstOrCreate(
            ['provider' => 'razorpay', 'event_hash' => $eventHash],
            [
                'payment_transaction_id' => $transaction?->id,
                'provider_event_id' => $eventId,
                'event_type' => $eventType,
                'payload' => $payload,
                'signature_verified' => $verified,
                'processing_status' => 'PENDING',
            ]
        );

        if (! $verified) {
            $event->forceFill([
                'processing_status' => 'FAILED',
                'error_message' => 'Invalid webhook signature.',
            ])->save();

            throw new RuntimeException('Invalid webhook signature.');
        }

        if ($event->processed_at !== null) {
            return $event;
        }

        DB::transaction(function () use ($event, $eventType, $payload, $transaction): void {
            if ($transaction && in_array($eventType, ['payment.captured', 'order.paid'], true)) {
                $paymentEntity = data_get($payload, 'payload.payment.entity', []);
                $paymentEntity = is_array($paymentEntity) ? $paymentEntity : [];
                $paymentId = data_get($paymentEntity, 'id', $transaction->provider_payment_id);
                $this->applyPaymentEntity($transaction, $paymentEntity);
                $transaction->forceFill([
                    'provider_payment_id' => $paymentId,
                    'status' => 'CAPTURED',
                    'paid_at' => $transaction->paid_at ?? now(),
                    'verified_at' => $transaction->verified_at ?? now(),
                    'captured_at' => $transaction->captured_at ?? $this->paymentCapturedAt($paymentEntity),
                ])->save();
                if ($transaction->user_id) {
                    $user = User::query()->find($transaction->user_id);
                    if ($user) {
                        $this->updateUserContactFromPayment($user, $paymentEntity, $transaction);
                    }
                }
                $this->accessService->activate($transaction);
            }

            if ($transaction && $eventType === 'payment.failed') {
                $transaction->forceFill([
                    'status' => 'FAILED',
                    'failure_code' => data_get($payload, 'payload.payment.entity.error_code'),
                    'failure_description' => data_get($payload, 'payload.payment.entity.error_description'),
                ])->save();
            }

            $event->forceFill([
                'payment_transaction_id' => $transaction?->id,
                'signature_verified' => true,
                'processing_status' => 'PROCESSED',
                'processed_at' => now(),
            ])->save();
        });

        return $event->refresh();
    }

    public function checkoutPrefill(User $user): array
    {
        $prefill = [
            'name' => $user->name,
            'email' => $user->email,
        ];

        if ($this->isValidE164($user->phone_e164)) {
            $prefill['contact'] = $user->phone_e164;
        }

        return array_filter($prefill, fn ($value): bool => $value !== null && $value !== '');
    }

    public function validPaymentSignature(string $orderId, string $paymentId, string $signature): bool
    {
        $secret = (string) config('services.razorpay.key_secret');
        if ($secret === '') {
            return str_starts_with($signature, 'test_');
        }

        $expected = hash_hmac('sha256', $orderId.'|'.$paymentId, $secret);

        return hash_equals($expected, $signature);
    }

    public function validWebhookSignature(string $rawBody, string $signature): bool
    {
        $secret = (string) config('services.razorpay.webhook_secret');
        if ($secret === '') {
            return str_starts_with($signature, 'test_');
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature);
    }

    private function createProviderOrder(PaymentTransaction $transaction, PredictionProduct $product): string
    {
        $keyId = config('services.razorpay.key_id');
        $keySecret = config('services.razorpay.key_secret');

        if (! $keyId || ! $keySecret) {
            return 'order_test_'.$transaction->id.'_'.Str::random(10);
        }

        $response = Http::withBasicAuth($keyId, $keySecret)
            ->asJson()
            ->post('https://api.razorpay.com/v1/orders', [
                'amount' => $transaction->amount_minor,
                'currency' => $transaction->currency,
                'receipt' => 'ygt_future_'.$transaction->id,
                'notes' => [
                    'prediction_request_id' => (string) $transaction->prediction_request_id,
                    'product_code' => $product->code,
                ],
            ])
            ->throw()
            ->json();

        return (string) $response['id'];
    }

    private function fetchPaymentEntity(string $paymentId): array
    {
        $keyId = config('services.razorpay.key_id');
        $keySecret = config('services.razorpay.key_secret');

        if (! $keyId || ! $keySecret) {
            return [];
        }

        $response = Http::withBasicAuth($keyId, $keySecret)
            ->acceptJson()
            ->get('https://api.razorpay.com/v1/payments/'.$paymentId)
            ->throw()
            ->json();

        return is_array($response) ? $response : [];
    }

    private function applyPaymentEntity(PaymentTransaction $transaction, array $paymentEntity): void
    {
        $metadata = is_array($transaction->metadata) ? $transaction->metadata : [];
        $metadata['razorpay_payment'] = $this->safePaymentMetadata($paymentEntity);

        $transaction->forceFill([
            'payment_contact' => data_get($paymentEntity, 'contact', $transaction->payment_contact),
            'payment_email' => data_get($paymentEntity, 'email', $transaction->payment_email),
            'metadata' => $metadata,
        ]);
    }

    private function updateUserContactFromPayment(User $user, array $paymentEntity, PaymentTransaction $transaction): void
    {
        $rawContact = trim((string) data_get($paymentEntity, 'contact', ''));
        if ($rawContact === '') {
            return;
        }

        $normalized = $this->normalizeGlobalPhone($rawContact);
        if ($normalized === null) {
            $metadata = is_array($transaction->metadata) ? $transaction->metadata : [];
            $metadata['unparsed_payment_contact'] = $rawContact;
            $transaction->forceFill(['metadata' => $metadata])->save();

            Log::warning('Razorpay payment contact could not be parsed.', [
                'payment_transaction_id' => $transaction->id,
                'user_id' => $user->id,
            ]);

            return;
        }

        if ($this->isValidE164($user->phone_e164)) {
            return;
        }

        $user->forceFill([
            'phone_country_calling_code' => $normalized['calling_code'],
            'phone_number' => $normalized['national_number'],
            'phone_e164' => $normalized['e164'],
            'phone_source' => 'razorpay',
            'phone_verified_at' => null,
        ])->save();
    }

    private function normalizeGlobalPhone(string $contact): ?array
    {
        $candidate = preg_replace('/[^\d+]/', '', trim($contact));
        if (! is_string($candidate) || $candidate === '') {
            return null;
        }

        if (str_starts_with($candidate, '00')) {
            $candidate = '+'.substr($candidate, 2);
        }

        if (! str_starts_with($candidate, '+')) {
            return null;
        }

        $digits = substr($candidate, 1);
        if (! preg_match('/^\d{8,15}$/', $digits)) {
            return null;
        }

        return [
            'e164' => '+'.$digits,
            'calling_code' => '+'.$this->callingCodeFromDigits($digits),
            'national_number' => substr($digits, strlen($this->callingCodeFromDigits($digits))),
        ];
    }

    private function callingCodeFromDigits(string $digits): string
    {
        foreach ([3, 2, 1] as $length) {
            $code = substr($digits, 0, $length);
            if ($code !== '') {
                return $code;
            }
        }

        return '';
    }

    private function isValidE164(?string $value): bool
    {
        return is_string($value) && preg_match('/^\+\d{8,15}$/', $value) === 1;
    }

    private function paymentCapturedAt(array $paymentEntity): mixed
    {
        $createdAt = data_get($paymentEntity, 'created_at');

        return is_numeric($createdAt) ? now()->setTimestamp((int) $createdAt) : now();
    }

    private function safePaymentMetadata(array $paymentEntity): array
    {
        return collect($paymentEntity)
            ->except(['card', 'token', 'acquirer_data'])
            ->all();
    }

    private function recordEvent(
        ?PaymentTransaction $transaction,
        string $type,
        array $payload,
        bool $verified,
        string $status,
        ?string $error = null
    ): PaymentEvent {
        return PaymentEvent::query()->create([
            'payment_transaction_id' => $transaction?->id,
            'provider' => 'razorpay',
            'provider_event_id' => null,
            'event_hash' => hash('sha256', $type.json_encode($payload).now()->timestamp),
            'event_type' => $type,
            'payload' => $payload,
            'signature_verified' => $verified,
            'processed_at' => $status === 'PROCESSED' ? now() : null,
            'processing_status' => $status,
            'error_message' => $error,
        ]);
    }

    private function transactionFromWebhook(array $payload): ?PaymentTransaction
    {
        $orderId = data_get($payload, 'payload.payment.entity.order_id')
            ?? data_get($payload, 'payload.order.entity.id');

        if (! $orderId) {
            return null;
        }

        return PaymentTransaction::query()->where('provider_order_id', $orderId)->first();
    }
}
