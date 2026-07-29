<?php

namespace App\Services\Prediction;

use App\Models\PredictionRuleLog;
use Illuminate\Support\Facades\Log;
use Throwable;

class PredictionRuleLogService
{
    public function recordFailure(
        int $predictionRequestId,
        string $predictionType,
        ?PredictionYearSession $yearSession,
        RuleResult $ruleResult
    ): void {
        $result = $ruleResult->toArray();

        try {
            PredictionRuleLog::query()->create([
                'prediction_request_id' => $predictionRequestId,
                'prediction_type' => $predictionType,
                'target_year' => $yearSession?->targetYear,
                'target_date' => $yearSession?->targetDate->toDateString(),
                'age' => $result['age'] ?? $yearSession?->age,
                'rule_code' => $result['rule_code'] ?? null,
                'rule_name' => $result['rule_name'] ?? null,
                'status' => $result['status'] ?? 'ERROR',
                'error_code' => $this->errorCode($result),
                'message' => $result['remarks'] ?? null,
                'context_json' => $result,
            ]);
        } catch (Throwable $exception) {
            Log::warning('Prediction rule failure logging failed.', [
                'prediction_request_id' => $predictionRequestId,
                'rule_code' => $result['rule_code'] ?? null,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function errorCode(array $result): string
    {
        $remarks = strtolower((string) ($result['remarks'] ?? ''));
        $status = (string) ($result['status'] ?? '');

        return match (true) {
            str_contains($remarks, 'workbook') && str_contains($remarks, 'age') => 'AGE_WORKBOOK_NOT_FOUND',
            str_contains($remarks, 'mahadasha data') => 'DASHA_PERIOD_NOT_FOUND',
            str_contains($remarks, 'planet house position') => 'PLANET_POSITION_NOT_FOUND',
            str_contains($remarks, 'worksheet') => 'DASHA_WORKSHEET_NOT_FOUND',
            str_contains($remarks, 'lagnam') || str_contains($remarks, 'position row') => 'LAGNAM_POSITION_ROW_NOT_FOUND',
            str_contains($remarks, 'sequence year') => 'SEQUENCE_YEAR_NOT_FOUND',
            str_contains($remarks, 'video value') => 'VIDEO_ID_NOT_FOUND',
            str_contains($remarks, 'ambiguous') => 'AMBIGUOUS_EXCEL_MATCH',
            $status === 'ERROR' && str_contains($remarks, 'excel') => 'WORKBOOK_READ_ERROR',
            default => 'UNEXPECTED_RULE_ERROR',
        };
    }
}
