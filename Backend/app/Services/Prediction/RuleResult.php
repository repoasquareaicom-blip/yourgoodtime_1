<?php

namespace App\Services\Prediction;

class RuleResult
{
    public function __construct(
        private readonly string $ruleCode,
        private readonly string $ruleName,
        private readonly string $status,
        private readonly ?int $executionTimeMs = null,
        private readonly ?string $excelFile = null,
        private readonly ?string $worksheet = null,
        private readonly array $lookupValues = [],
        private readonly array $matchedValues = [],
        private readonly array $identifiedValues = [],
        private readonly ?string $predictionDate = null,
        private readonly ?int $calendarYear = null,
        private readonly ?int $excelYear = null,
        private readonly ?int $predictionEffectiveYear = null,
        private readonly ?string $dashaStartDate = null,
        private readonly ?int $dashaStartEffectiveYear = null,
        private readonly ?string $dashaEndDate = null,
        private readonly ?int $dashaEndEffectiveYear = null,
        private readonly ?int $sequenceBaseYear = null,
        private readonly ?int $sequenceOffset = null,
        private readonly ?int $dashaYearNumber = null,
        private readonly ?int $excelSequenceYear = null,
        private readonly ?string $cutoffDate = null,
        private readonly ?int $age = null,
        private readonly ?string $predictionPeriod = null,
        private readonly null|int|string $matchedRow = null,
        private readonly null|int|string $yearColumn = null,
        private readonly null|int|string $videoColumn = null,
        private readonly null|int|string $matchedColumn = null,
        private readonly null|int|string $matchedCell = null,
        private readonly mixed $resultValue = null,
        private readonly ?string $remarks = null
    ) {
    }

    public function toArray(): array
    {
        return [
            'rule_code' => $this->ruleCode,
            'rule_name' => $this->ruleName,
            'status' => $this->status,
            'execution_time_ms' => $this->executionTimeMs,
            'excel_file' => $this->excelFile,
            'worksheet' => $this->worksheet,
            'lookup_values' => $this->lookupValues,
            'identified_values' => $this->identifiedValues,
            'matched_values' => $this->matchedValues,
            'prediction_date' => $this->predictionDate,
            'calendar_year' => $this->calendarYear,
            'excel_year' => $this->excelYear,
            'prediction_effective_year' => $this->predictionEffectiveYear,
            'dasha_start_date' => $this->dashaStartDate,
            'dasha_start_effective_year' => $this->dashaStartEffectiveYear,
            'dasha_end_date' => $this->dashaEndDate,
            'dasha_end_effective_year' => $this->dashaEndEffectiveYear,
            'sequence_base_year' => $this->sequenceBaseYear,
            'sequence_offset' => $this->sequenceOffset,
            'dasha_year_number' => $this->dashaYearNumber,
            'excel_sequence_year' => $this->excelSequenceYear,
            'cutoff_date' => $this->cutoffDate,
            'age' => $this->age,
            'prediction_period' => $this->predictionPeriod,
            'matched_row' => $this->matchedRow,
            'year_column' => $this->yearColumn,
            'video_column' => $this->videoColumn,
            'matched_column' => $this->matchedColumn,
            'matched_cell' => $this->matchedCell,
            'result_value' => $this->resultValue,
            'remarks' => $this->remarks,
        ];
    }
}
