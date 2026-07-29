<?php

namespace App\Services\Prediction;

use Carbon\CarbonInterface;

class PredictionYearResolver
{
    public function resolveExcelYear(CarbonInterface $predictionDate): int
    {
        $cutoffDate = $predictionDate->copy()->month(4)->day(1)->startOfDay();

        return $predictionDate->lt($cutoffDate)
            ? (int) $predictionDate->copy()->subYear()->format('Y')
            : (int) $predictionDate->format('Y');
    }

    public function metadata(CarbonInterface $predictionDate): array
    {
        return [
            'prediction_date' => $predictionDate->toDateString(),
            'calendar_year' => (int) $predictionDate->format('Y'),
            'excel_year' => $this->resolveExcelYear($predictionDate),
            'cutoff_date' => 'April 1',
        ];
    }
}
