<?php

namespace App\Services;

use App\Models\PaymentTransaction;
use App\Models\PredictionEntitlement;
use App\Models\PredictionProduct;
use App\Models\PredictionRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class FuturePredictionAccessService
{
    public function activeProduct(): ?PredictionProduct
    {
        return PredictionProduct::query()
            ->where('code', 'FUTURE_PREDICTION')
            ->where('prediction_type', 'future')
            ->where('is_active', true)
            ->first();
    }

    public function activeProductById(int $productId): ?PredictionProduct
    {
        return PredictionProduct::query()
            ->whereKey($productId)
            ->where('prediction_type', 'future')
            ->where('is_active', true)
            ->first();
    }

    public function activeEntitlement(PredictionRequest $predictionRequest): ?PredictionEntitlement
    {
        $this->expireElapsedEntitlements($predictionRequest);

        return PredictionEntitlement::query()
            ->where('prediction_request_id', $predictionRequest->id)
            ->where('prediction_type', 'future')
            ->where('status', 'ACTIVE')
            ->where('expires_at', '>', now())
            ->latest('expires_at')
            ->first();
    }

    public function canPurchase(PredictionRequest $predictionRequest): bool
    {
        return $this->activeEntitlement($predictionRequest) === null;
    }

    public function activate(PaymentTransaction $transaction): PredictionEntitlement
    {
        return DB::transaction(function () use ($transaction): PredictionEntitlement {
            $transaction->refresh();
            $product = PredictionProduct::query()->lockForUpdate()->findOrFail($transaction->product_id);
            $predictionRequest = PredictionRequest::query()->lockForUpdate()->findOrFail($transaction->prediction_request_id);
            $timezone = config('services.prediction_billing.business_timezone', 'Asia/Kolkata');
            $now = CarbonImmutable::now($timezone);
            $startYear = $now->year + 1;
            $futureYears = max(1, (int) config('prediction.future_years', 40));
            $endYear = $startYear + $futureYears - 1;
            $expiresAt = CarbonImmutable::create($endYear, 12, 31, 23, 59, 59, $timezone);

            return PredictionEntitlement::query()->updateOrCreate(
                [
                    'payment_transaction_id' => $transaction->id,
                    'prediction_type' => 'future',
                ],
                [
                    'prediction_request_id' => $predictionRequest->id,
                    'user_id' => $transaction->user_id,
                    'product_id' => $product->id,
                    'purchased_years' => $futureYears,
                    'access_start_year' => $startYear,
                    'access_end_year' => $endYear,
                    'starts_at' => $now,
                    'expires_at' => $expiresAt,
                    'purchased_at' => $transaction->paid_at ?? $now,
                    'status' => 'ACTIVE',
                ]
            );
        });
    }

    public function expireElapsedEntitlements(PredictionRequest $predictionRequest): void
    {
        PredictionEntitlement::query()
            ->where('prediction_request_id', $predictionRequest->id)
            ->where('prediction_type', 'future')
            ->where('status', 'ACTIVE')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'EXPIRED']);
    }

    public function productPayload(?PredictionProduct $product): ?array
    {
        if ($product === null) {
            return null;
        }

        return [
            'code' => $product->code,
            'product_id' => $product->id,
            'name' => $product->name,
            'title' => $product->name,
            'description' => $product->description,
            'display_price' => $this->displayPrice($product->price_inclusive_tax_minor, $product->currency),
            'amount_minor' => $product->price_inclusive_tax_minor,
            'currency' => $product->currency,
            'tax_inclusive' => $product->tax_inclusive,
            'future_years' => max(1, (int) config('prediction.future_years', 40)),
            'duration_label' => 'Next '.max(1, (int) config('prediction.future_years', 40)).' Years',
        ];
    }

    public function entitlementPayload(?PredictionEntitlement $entitlement): ?array
    {
        if ($entitlement === null) {
            return null;
        }

        return [
            'status' => $entitlement->status,
            'access_start_year' => $entitlement->access_start_year,
            'access_end_year' => $entitlement->access_end_year,
            'purchased_years' => $entitlement->purchased_years,
            'purchased_at' => $entitlement->purchased_at?->toJSON(),
            'expires_at' => $entitlement->expires_at?->toJSON(),
        ];
    }

    private function displayPrice(int $amountMinor, string $currency): string
    {
        $amount = number_format($amountMinor / 100, 0);

        return strtoupper($currency) === 'INR' ? 'Rs '.$amount : $currency.' '.$amount;
    }
}
