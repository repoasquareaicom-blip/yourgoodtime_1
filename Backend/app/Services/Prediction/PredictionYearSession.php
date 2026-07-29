<?php

namespace App\Services\Prediction;

use Carbon\CarbonImmutable;

class PredictionYearSession
{
    public function __construct(
        public readonly string $predictionType,
        public readonly int $targetYear,
        public readonly CarbonImmutable $targetDate,
        public readonly int $periodSequence,
        public readonly ?int $age = null
    ) {
    }
}
