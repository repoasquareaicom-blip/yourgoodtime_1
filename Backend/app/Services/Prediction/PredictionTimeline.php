<?php

namespace App\Services\Prediction;

use App\Models\PredictionRequest;

class PredictionTimeline
{
    /**
     * @param array<int, PredictionYearSession> $yearSessions
     * @param array<int, array<string, mixed>> $yearlyPredictions
     * @param array<int, RuleResult> $ruleResults
     */
    public function __construct(
        public readonly PredictionRequest $predictionRequest,
        public readonly string $predictionType,
        public readonly int $effectiveYear,
        public readonly int $fromYear,
        public readonly int $toYear,
        public readonly int $configuredYears,
        public readonly int $candidateYears,
        public readonly int $processedYears,
        public readonly int $excludedAgeCount,
        public readonly int $excludedPurchasedCount,
        public readonly array $excludedPurchasedYears,
        public readonly int $minimumSupportedAge,
        public readonly int $maximumSupportedAge,
        public readonly string $status,
        public readonly array $yearSessions,
        public readonly array $introPlaylist,
        public readonly array $yearlyPredictions,
        public readonly array $ruleResults,
        public readonly DeveloperTrace $developerTrace
    ) {
    }
}
