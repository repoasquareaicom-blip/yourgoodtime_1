<?php

namespace App\Services\Prediction;

class DashaSequenceYearResult
{
    public function __construct(
        public readonly string $predictionDate,
        public readonly int $predictionEffectiveYear,
        public readonly string $dashaStartDate,
        public readonly int $dashaStartEffectiveYear,
        public readonly string $dashaEndDate,
        public readonly int $dashaEndEffectiveYear,
        public readonly int $sequenceBaseYear,
        public readonly int $sequenceOffset,
        public readonly int $dashaYearNumber,
        public readonly int $excelSequenceYear,
        public readonly bool $isWithinPeriod,
        public readonly ?string $remarks = null
    ) {
    }
}
