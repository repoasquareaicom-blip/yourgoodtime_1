<?php

namespace App\Services\Prediction;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Throwable;

class DashaSequenceYearResolver
{
    public function __construct(
        private readonly PredictionYearResolver $yearResolver,
        private readonly PredictionConfig $predictionConfig
    ) {
    }

    public function resolve(CarbonInterface $predictionDate, string $dashaStartDate, string $dashaEndDate, string $predictionType = 'past'): DashaSequenceYearResult
    {
        $start = $this->parseDate($dashaStartDate);
        $end = $this->parseDate($dashaEndDate);

        if ($start === null || $end === null) {
            throw new \InvalidArgumentException('Dasha start or end date could not be parsed.');
        }

        $predictionEffectiveYear = $this->yearResolver->resolveExcelYear($predictionDate);
        $dashaStartEffectiveYear = $this->yearResolver->resolveExcelYear($start);
        $dashaEndEffectiveYear = $this->yearResolver->resolveExcelYear($end);
        $sequenceOffset = $predictionEffectiveYear - $dashaStartEffectiveYear;
        $sequenceBaseYear = $this->predictionConfig->dashaExcelSequenceStart($predictionType);
        $isWithinPeriod = $predictionDate->greaterThanOrEqualTo($start) && $predictionDate->lessThan($end);

        return new DashaSequenceYearResult(
            predictionDate: $predictionDate->toDateString(),
            predictionEffectiveYear: $predictionEffectiveYear,
            dashaStartDate: $dashaStartDate,
            dashaStartEffectiveYear: $dashaStartEffectiveYear,
            dashaEndDate: $dashaEndDate,
            dashaEndEffectiveYear: $dashaEndEffectiveYear,
            sequenceBaseYear: $sequenceBaseYear,
            sequenceOffset: $sequenceOffset,
            dashaYearNumber: $sequenceOffset + 1,
            excelSequenceYear: $sequenceBaseYear + $sequenceOffset,
            isWithinPeriod: $isWithinPeriod,
            remarks: $isWithinPeriod ? null : 'Prediction date is outside selected Mahadasha period.'
        );
    }

    public function resolveForSystemYear(int $systemYear, string $dashaStartDate, string $dashaEndDate, string $predictionType = 'past'): DashaSequenceYearResult
    {
        $start = $this->parseDate($dashaStartDate);
        $end = $this->parseDate($dashaEndDate);

        if ($start === null || $end === null) {
            throw new \InvalidArgumentException('Dasha start or end date could not be parsed.');
        }

        $dashaStartYear = (int) $start->format('Y');
        $dashaEndYear = (int) $end->format('Y');
        $sequenceOffset = $systemYear - $dashaStartYear;
        $sequenceBaseYear = $this->predictionConfig->dashaExcelSequenceStart($predictionType);
        $isWithinPeriod = $systemYear >= $dashaStartYear && $systemYear < $dashaEndYear;

        return new DashaSequenceYearResult(
            predictionDate: (string) $systemYear,
            predictionEffectiveYear: $systemYear,
            dashaStartDate: $dashaStartDate,
            dashaStartEffectiveYear: $dashaStartYear,
            dashaEndDate: $dashaEndDate,
            dashaEndEffectiveYear: $dashaEndYear,
            sequenceBaseYear: $sequenceBaseYear,
            sequenceOffset: $sequenceOffset,
            dashaYearNumber: $sequenceOffset + 1,
            excelSequenceYear: $sequenceBaseYear + $sequenceOffset,
            isWithinPeriod: $isWithinPeriod,
            remarks: $isWithinPeriod ? null : 'System year is outside selected Mahadasha period.'
        );
    }

    private function parseDate(string $value): ?CarbonImmutable
    {
        $value = preg_replace('/\s+/', ' ', trim($value));

        foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d', 'd-m-Y H:i', 'j-n-Y H:i', 'd-m-Y', 'j-n-Y'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat($format, $value);

                if ($date !== false) {
                    return str_contains($format, 'H') ? $date : $date->startOfDay();
                }
            } catch (Throwable) {
            }
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
