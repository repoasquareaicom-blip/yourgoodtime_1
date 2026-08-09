<?php

namespace App\Services;

use App\Models\PaymentTransaction;
use App\Models\PredictionEntitlement;
use App\Models\PredictionProduct;
use App\Models\PredictionRequest;
use App\Models\User;
use App\Services\Prediction\PredictionEngineService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class FuturePredictionAccessService
{
    public function __construct(private readonly PredictionEngineService $predictionEngineService)
    {
    }

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
        $ownedYears = $this->ownedFutureYears($user);
        $candidateRows = [];

        $scanYears = max($configuredYears, $maximumAge - $currentAge);

        for ($offset = 1; $offset <= $scanYears; $offset++) {
            $year = $currentYear + $offset;
            $age = $currentAge + $offset;
            $candidateRows[] = ['year' => $year, 'age' => $age];

            if ($age < $minimumAge || $age > $maximumAge || in_array($year, $ownedYears, true)) {
                continue;
            }

            $mappedBlock = $this->mappedFutureBlock($predictionRequest, $year, $currentYear, $currentAge, $minimumAge, $maximumAge, $ownedYears);

            if (($mappedBlock['can_purchase'] ?? false) || ($mappedBlock['reason'] ?? null) === 'PARTIALLY_OWNED_FUTURE_RANGE_CONFLICT') {
                return array_merge([
                    'configured_years' => $configuredYears,
                    'candidate_years' => count($candidateRows),
                    'excluded_by_age_limit' => count(array_filter(
                        $candidateRows,
                        fn (array $row): bool => $row['age'] < $minimumAge || $row['age'] > $maximumAge
                    )),
                    'minimum_supported_age' => $minimumAge,
                    'maximum_supported_age' => $maximumAge,
                    'owned_future_years' => $ownedYears,
                ], $mappedBlock);
            }
        }

        if ($candidateRows === [] || count(array_filter(
            $candidateRows,
            fn (array $row): bool => $row['age'] >= $minimumAge && $row['age'] <= $maximumAge
        )) === 0) {
            return array_merge($this->unavailableBlock($configuredYears, 'NO_NEW_FUTURE_YEARS_AVAILABLE', 'You already have all currently supported future predictions.'), [
                'excluded_by_age_limit' => count($candidateRows),
                'minimum_supported_age' => $minimumAge,
                'maximum_supported_age' => $maximumAge,
                'owned_future_years' => $ownedYears,
            ]);
        }

        return array_merge(
            $this->unavailableBlock($configuredYears, 'NO_FUTURE_PREDICTION_MAPPING', 'Future Prediction is not currently available for the next supported period.'),
            [
                'status' => 'NO_FUTURE_PREDICTION_MAPPING',
                'candidate_years' => count($candidateRows),
                'minimum_supported_age' => $minimumAge,
                'maximum_supported_age' => $maximumAge,
                'owned_future_years' => $ownedYears,
            ]
        );
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

    /**
     * @param array<int, int> $ownedYears
     * @return array<string, mixed>
     */
    private function mappedFutureBlock(PredictionRequest $predictionRequest, int $targetYear, int $currentYear, int $currentAge, int $minimumAge, int $maximumAge, array $ownedYears): array
    {
        $preview = $this->predictionEngineService->previewFutureYear($predictionRequest->id, $targetYear);
        $prediction = $preview['prediction'];
        $ruleData = $preview['rule_data'];

        if (($prediction['status'] ?? null) !== 'SUCCESS') {
            return $this->unavailableBlock(1, 'NO_FUTURE_PREDICTION_MAPPING', 'Future Prediction is not currently available for the next supported period.');
        }

        $matchedRange = $this->matchedExcelRange((string) data_get($ruleData, 'matched_values.matched_year', ''));
        $sequenceBase = (int) ($ruleData['sequence_base_year'] ?? data_get($ruleData, 'matched_values.sequence_base_year', 0));
        $dashaStartYear = (int) ($ruleData['dasha_start_effective_year'] ?? 0);
        $dashaEndYear = (int) ($ruleData['dasha_end_effective_year'] ?? PHP_INT_MAX);

        if ($matchedRange === null || $sequenceBase === 0 || $dashaStartYear === 0) {
            return $this->unavailableBlock(1, 'NO_FUTURE_PREDICTION_MAPPING', 'Future Prediction is not currently available for the next supported period.');
        }

        [$dummyFrom, $dummyTo, $matchedLabel] = $matchedRange;
        $actualFrom = $dashaStartYear + ($dummyFrom - $sequenceBase);
        $actualTo = $dashaStartYear + ($dummyTo - $sequenceBase);
        $eligibleYears = [];

        for ($year = $actualFrom; $year <= min($actualTo, $dashaEndYear); $year++) {
            $age = $currentAge + ($year - $currentYear);
            if ($year > $currentYear && $age >= $minimumAge && $age <= $maximumAge && ! in_array($year, $ownedYears, true)) {
                $eligibleYears[] = $year;
            }
        }

        if ($eligibleYears === []) {
            return $this->unavailableBlock(1, 'NO_NEW_FUTURE_YEARS_AVAILABLE', 'You already have all currently supported future predictions.');
        }

        $first = min($eligibleYears);
        $last = max($eligibleYears);
        if (count($eligibleYears) !== ($last - $first + 1)) {
            return array_merge($this->unavailableBlock(1, 'PARTIALLY_OWNED_FUTURE_RANGE_CONFLICT', 'The next Future prediction range overlaps previously purchased years in a non-contiguous way.'), [
                'status' => 'PARTIALLY_OWNED_FUTURE_RANGE_CONFLICT',
                'matched_excel_year' => $matchedLabel,
                'eligible_years' => $eligibleYears,
                'owned_future_years' => $ownedYears,
            ]);
        }

        $startAge = $currentAge + ($first - $currentYear);
        $endAge = $currentAge + ($last - $currentYear);

        return [
            'can_purchase' => true,
            'available_years' => count($eligibleYears),
            'next_start_year' => $first,
            'next_end_year' => $last,
            'next_start_age' => $startAge,
            'next_end_age' => $endAge,
            'start_year' => $first,
            'end_year' => $last,
            'start_system_year' => $first,
            'end_system_year' => $last,
            'start_age' => $startAge,
            'end_age' => $endAge,
            'reason' => null,
            'status' => 'READY',
            'matched_excel_year' => $matchedLabel,
            'matched_excel_year_from' => $dummyFrom,
            'matched_excel_year_to' => $dummyTo,
            'sequence_base_year' => $sequenceBase,
            'target_system_year' => $targetYear,
            'target_age' => $currentAge + ($targetYear - $currentYear),
            'dasha_start_year' => $dashaStartYear,
            'dasha_end_year' => $dashaEndYear,
            'actual_system_year_from' => $actualFrom,
            'actual_system_year_to' => $actualTo,
            'eligible_system_year_from' => $first,
            'eligible_system_year_to' => $last,
            'actual_age_from' => $currentAge + ($actualFrom - $currentYear),
            'actual_age_to' => $currentAge + ($actualTo - $currentYear),
            'eligible_age_from' => $startAge,
            'eligible_age_to' => $endAge,
            'excluded_purchased_years' => array_values(array_intersect(range($actualFrom, $actualTo), $ownedYears)),
            'excel_file' => $ruleData['excel_file'] ?? null,
            'sheet' => $ruleData['worksheet'] ?? null,
            'position' => data_get($ruleData, 'matched_values.position'),
            'excel_row_number' => $ruleData['matched_row'] ?? null,
            'year_column' => $ruleData['year_column'] ?? null,
            'video_id' => data_get($ruleData, 'matched_values.video_ids.0'),
        ];
    }

    /**
     * @return array{0:int,1:int,2:string}|null
     */
    private function matchedExcelRange(string $value): ?array
    {
        $label = preg_replace('/\s+/', '', trim($value));

        if (! is_string($label) || $label === '') {
            return null;
        }

        preg_match_all('/\d{4}/', $label, $matches);
        $years = array_values(array_map('intval', $matches[0] ?? []));

        if ($years === []) {
            return null;
        }

        return [min($years), max($years), count($years) === 1 ? (string) $years[0] : min($years).'-'.max($years)];
    }

    /**
     * @return array<int, int>
     */
    private function ownedFutureYears(User $user): array
    {
        $years = [];
        PredictionEntitlement::query()
            ->where('user_id', $user->id)
            ->where('prediction_type', 'future')
            ->whereIn('status', ['ACTIVE', 'EXPIRED', 'COMPLETED'])
            ->whereNotNull('access_start_year')
            ->whereNotNull('access_end_year')
            ->get(['access_start_year', 'access_end_year'])
            ->each(function (PredictionEntitlement $entitlement) use (&$years): void {
                for ($year = (int) $entitlement->access_start_year; $year <= (int) $entitlement->access_end_year; $year++) {
                    $years[] = $year;
                }
            });

        sort($years);

        return array_values(array_unique($years));
    }

    private function displayPrice(int $amountMinor, string $currency): string
    {
        $amount = number_format($amountMinor / 100, 0);

        return strtoupper($currency) === 'INR' ? 'Rs '.$amount : $currency.' '.$amount;
    }
}
