<?php

namespace App\Services\Prediction;

class PredictionConfig
{
    public function pastYears(): int
    {
        return (int) config('prediction.past_years', 10);
    }

    public function futureYears(): int
    {
        return (int) config('prediction.future_years', 1);
    }

    /**
     * @return array{minimum:int,maximum:int}
     */
    public function ageLimits(string $predictionType): array
    {
        $minimum = (int) config("prediction.age_limits.{$predictionType}.minimum", 0);
        $maximum = (int) config("prediction.age_limits.{$predictionType}.maximum", PHP_INT_MAX);

        return [
            'minimum' => $minimum,
            'maximum' => max($minimum, $maximum),
        ];
    }

    public function engineVersion(): string
    {
        return (string) config('prediction.engine_version', '1.0.0');
    }

    public function developerTraceEnabled(): bool
    {
        return (bool) config('prediction.developer_trace', true);
    }

    public function debugResponseEnabled(): bool
    {
        return (bool) config('prediction.debug_response', false);
    }

    public function excelPath(): string
    {
        return (string) config('prediction.excel_path', storage_path('app/prediction-data'));
    }

    public function dashaExcelSequenceStart(): int
    {
        return (int) config('prediction.dasha_excel_sequence_start', 2007);
    }

    public function predictionFile(string $key): string
    {
        return (string) config("prediction.files.{$key}", '');
    }
}
