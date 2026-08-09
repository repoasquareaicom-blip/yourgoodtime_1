<?php

namespace App\Services\Prediction;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class PredictionTimelineGenerator
{
    public function __construct(
        private readonly PredictionConfig $predictionConfig,
        private readonly PredictionYearResolver $yearResolver,
        private readonly AgeResolver $ageResolver
    ) {
    }

    /**
     * @param array<int, int> $excludedYears
     *
     * @return array{effective_year:int,from_year:int,to_year:int,year_sessions:array<int, PredictionYearSession>,configured_years:int,candidate_years:int,processed_years:int,excluded_age_count:int,excluded_purchased_count:int,excluded_purchased_years:array<int, int>,minimum_supported_age:int,maximum_supported_age:int,status:string}
     */
    public function generate(string $predictionType, CarbonInterface $predictionDate, ?CarbonInterface $birthDate, ?int $futureStartYear = null, ?int $futureYearCount = null, array $excludedYears = []): array
    {
        $businessYear = (int) CarbonImmutable::now(config('services.prediction_billing.business_timezone', 'Asia/Kolkata'))->format('Y');
        $effectiveYear = (int) $predictionDate->format('Y');
        if ($predictionType === 'past') {
            $effectiveYear = min($effectiveYear, $businessYear);
        }
        $configuredYears = $predictionType === 'past'
            ? $this->predictionConfig->pastYears()
            : ($futureYearCount ?? $this->predictionConfig->futureYears());
        $ageLimits = $this->predictionConfig->ageLimits($predictionType);
        $sessions = [];

        if ($birthDate === null) {
            return [
                'effective_year' => $effectiveYear,
                'from_year' => $effectiveYear,
                'to_year' => $effectiveYear,
                'year_sessions' => [],
                'configured_years' => $configuredYears,
                'candidate_years' => 0,
                'processed_years' => 0,
                'excluded_age_count' => 0,
                'excluded_purchased_count' => 0,
                'excluded_purchased_years' => [],
                'minimum_supported_age' => $ageLimits['minimum'],
                'maximum_supported_age' => $ageLimits['maximum'],
                'status' => 'NO_BIRTH_DATE',
            ];
        }

        $currentAge = $this->ageResolver->calculateAge($birthDate, $predictionDate);
        $candidateRows = [];

        $startFutureYear = $futureStartYear ?? ($effectiveYear + 1);

        for ($index = 0; $index < $configuredYears; $index++) {
            $futureYear = $startFutureYear + $index;
            $candidateRows[] = [
                'index' => $index,
                'age' => $predictionType === 'past'
                    ? $currentAge - $index
                    : $currentAge + ($futureYear - $effectiveYear),
                'year' => $predictionType === 'past'
                    ? $effectiveYear - $index
                    : $futureYear,
            ];
        }

        $supportedRows = array_values(array_filter(
            $candidateRows,
            fn (array $row): bool => $row['age'] >= $ageLimits['minimum'] && $row['age'] <= $ageLimits['maximum']
        ));
        $excludedAgeCount = count($candidateRows) - count($supportedRows);
        $excludedYears = array_values(array_unique(array_map('intval', $excludedYears)));
        $excludedYearMap = array_flip($excludedYears);
        $excludedPurchasedRows = $predictionType === 'past'
            ? array_values(array_filter($supportedRows, fn (array $row): bool => isset($excludedYearMap[(int) $row['year']])))
            : [];
        if ($predictionType === 'past' && $excludedPurchasedRows !== []) {
            $supportedRows = array_values(array_filter($supportedRows, fn (array $row): bool => ! isset($excludedYearMap[(int) $row['year']])));
        }
        $years = array_column($supportedRows !== [] ? $supportedRows : $candidateRows, 'year');
        $fromYear = $years === [] ? $effectiveYear : min($years);
        $toYear = $years === [] ? $effectiveYear : max($years);

        foreach ($supportedRows as $row) {
            $targetDate = CarbonImmutable::create((int) $row['year'], 1, 1)->startOfDay();
            $sessions[] = new PredictionYearSession(
                predictionType: $predictionType,
                targetYear: (int) $row['year'],
                targetDate: $targetDate,
                periodSequence: $row['index'] + 1,
                age: $row['age']
            );
        }

        return [
            'effective_year' => $effectiveYear,
            'from_year' => $fromYear,
            'to_year' => $toYear,
            'year_sessions' => $sessions,
            'configured_years' => $configuredYears,
            'candidate_years' => count($candidateRows),
            'processed_years' => count($sessions),
            'excluded_age_count' => $excludedAgeCount,
            'excluded_purchased_count' => count($excludedPurchasedRows),
            'excluded_purchased_years' => array_values(array_map(fn (array $row): int => (int) $row['year'], $excludedPurchasedRows)),
            'minimum_supported_age' => $ageLimits['minimum'],
            'maximum_supported_age' => $ageLimits['maximum'],
            'status' => $sessions === [] ? 'NO_SUPPORTED_AGES' : 'READY',
        ];
    }
}
