<?php

namespace App\Services;

use App\Models\PaymentTransaction;
use App\Models\PredictionEntitlement;
use App\Models\PredictionProduct;
use App\Models\PredictionRequest;
use App\Models\User;
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

    public function purchasedEntitlements(User $user): \Illuminate\Support\Collection
    {
        return PredictionEntitlement::query()
            ->where('user_id', $user->id)
            ->where('prediction_type', 'future')
            ->where('status', 'ACTIVE')
            ->orderBy('access_start_year')
            ->get();
    }

    public function canPurchase(PredictionRequest $predictionRequest): bool
    {
        if ($predictionRequest->email === null) {
            return false;
        }

        $user = User::query()->where('email', $predictionRequest->email)->first();

        return $user !== null && $this->nextPurchaseBlock($user, $predictionRequest)['can_purchase'];
    }

    /**
     * @return array<string, mixed>
     */
    public function nextPurchaseBlock(User $user, PredictionRequest $predictionRequest): array
    {
        $timezone = config('services.prediction_billing.business_timezone', 'Asia/Kolkata');
        $now = CarbonImmutable::now($timezone);
        $currentYear = $now->year;
        $configuredYears = max(1, (int) config('prediction.future_years', 40));
        $ageLimits = config('prediction.age_limits.future', ['minimum' => 22, 'maximum' => 60]);
        $minimumAge = (int) ($ageLimits['minimum'] ?? 22);
        $maximumAge = (int) ($ageLimits['maximum'] ?? 60);
        $birthDate = $predictionRequest->date_of_birth;

        if ($birthDate === null) {
            return $this->unavailableBlock($configuredYears, 'MISSING_BIRTH_DATE', 'Birth details are required before future prediction purchase.');
        }

        $currentAge = (int) CarbonImmutable::instance($birthDate)->diffInYears($now);
        $maxOwnedYear = PredictionEntitlement::query()
            ->where('user_id', $user->id)
            ->where('prediction_type', 'future')
            ->where('status', 'ACTIVE')
            ->max('access_end_year');

        $startYear = max($currentYear + 1, ((int) $maxOwnedYear) + 1);
        $candidateRows = [];
        for ($index = 0; $index < $configuredYears; $index++) {
            $year = $startYear + $index;
            $age = $currentAge + ($year - $currentYear);
            $candidateRows[] = ['year' => $year, 'age' => $age];
        }

        $validRows = array_values(array_filter(
            $candidateRows,
            fn (array $row): bool => $row['age'] >= $minimumAge && $row['age'] <= $maximumAge
        ));

        if ($validRows === []) {
            return array_merge($this->unavailableBlock($configuredYears, 'NO_NEW_FUTURE_YEARS_AVAILABLE', 'You already have all currently supported future predictions.'), [
                'excluded_by_age_limit' => count($candidateRows),
                'minimum_supported_age' => $minimumAge,
                'maximum_supported_age' => $maximumAge,
            ]);
        }

        $first = $validRows[0];
        $last = $validRows[array_key_last($validRows)];

        return [
            'can_purchase' => true,
            'configured_years' => $configuredYears,
            'available_years' => count($validRows),
            'next_start_year' => $first['year'],
            'next_end_year' => $last['year'],
            'next_start_age' => $first['age'],
            'next_end_age' => $last['age'],
            'start_year' => $first['year'],
            'end_year' => $last['year'],
            'start_age' => $first['age'],
            'end_age' => $last['age'],
            'excluded_by_age_limit' => count($candidateRows) - count($validRows),
            'minimum_supported_age' => $minimumAge,
            'maximum_supported_age' => $maximumAge,
            'reason' => null,
        ];
    }

    public function activate(PaymentTransaction $transaction): PredictionEntitlement
    {
        return DB::transaction(function () use ($transaction): PredictionEntitlement {
            $transaction->refresh();
            $existing = PredictionEntitlement::query()
                ->where('payment_transaction_id', $transaction->id)
                ->where('prediction_type', 'future')
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $product = PredictionProduct::query()->lockForUpdate()->findOrFail($transaction->product_id);
            $predictionRequest = PredictionRequest::query()->lockForUpdate()->findOrFail($transaction->prediction_request_id);
            $user = User::query()->lockForUpdate()->findOrFail($transaction->user_id);
            PredictionEntitlement::query()
                ->where('user_id', $user->id)
                ->where('prediction_type', 'future')
                ->lockForUpdate()
                ->get();

            $timezone = config('services.prediction_billing.business_timezone', 'Asia/Kolkata');
            $now = CarbonImmutable::now($timezone);
            $block = $this->nextPurchaseBlock($user, $predictionRequest);

            if (! $block['can_purchase']) {
                throw new \RuntimeException($block['message'] ?? 'No new future years are available.');
            }

            $startYear = (int) $block['next_start_year'];
            $endYear = (int) $block['next_end_year'];
            $expiresAt = CarbonImmutable::create($endYear, 12, 31, 23, 59, 59, $timezone);

            return PredictionEntitlement::query()->create(
                [
                    'prediction_request_id' => $predictionRequest->id,
                    'user_id' => $transaction->user_id,
                    'product_id' => $product->id,
                    'payment_transaction_id' => $transaction->id,
                    'prediction_type' => 'future',
                    'purchased_years' => (int) $block['available_years'],
                    'access_start_year' => $startYear,
                    'access_end_year' => $endYear,
                    'start_age' => (int) $block['next_start_age'],
                    'end_age' => (int) $block['next_end_age'],
                    'allocated_year_count' => (int) $block['available_years'],
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
            'start_age' => $entitlement->start_age,
            'end_age' => $entitlement->end_age,
            'allocated_year_count' => $entitlement->allocated_year_count ?? $entitlement->purchased_years,
            'purchased_years' => $entitlement->purchased_years,
            'purchased_at' => $entitlement->purchased_at?->toJSON(),
            'expires_at' => $entitlement->expires_at?->toJSON(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function purchasedBlockPayload(PredictionEntitlement $entitlement): array
    {
        return [
            'entitlement_id' => $entitlement->id,
            'start_year' => $entitlement->access_start_year,
            'end_year' => $entitlement->access_end_year,
            'start_age' => $entitlement->start_age,
            'end_age' => $entitlement->end_age,
            'allocated_year_count' => $entitlement->allocated_year_count ?? $entitlement->purchased_years,
            'result_ready' => $entitlement->generated_prediction_result_id !== null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function unavailableBlock(int $configuredYears, string $reason, string $message): array
    {
        return [
            'can_purchase' => false,
            'configured_years' => $configuredYears,
            'available_years' => 0,
            'next_start_year' => null,
            'next_end_year' => null,
            'next_start_age' => null,
            'next_end_age' => null,
            'start_year' => null,
            'end_year' => null,
            'start_age' => null,
            'end_age' => null,
            'excluded_by_age_limit' => 0,
            'reason' => $reason,
            'message' => $message,
        ];
    }

    private function displayPrice(int $amountMinor, string $currency): string
    {
        $amount = number_format($amountMinor / 100, 0);

        return strtoupper($currency) === 'INR' ? 'Rs '.$amount : $currency.' '.$amount;
    }
}
