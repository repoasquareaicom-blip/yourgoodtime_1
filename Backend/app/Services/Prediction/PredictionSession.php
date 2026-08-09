<?php

namespace App\Services\Prediction;

use Carbon\CarbonImmutable;

class PredictionSession
{
    private int $requestId;

    private CarbonImmutable $currentDate;

    private int $currentYear;

    private int $pastYears;

    private int $futureYears;

    private int $pastStartYear;

    private int $pastEndYear;

    private int $futureStartYear;

    private int $futureEndYear;

    private PredictionContext $predictionContext;

    private PlaylistBuilder $playlistBuilder;

    /**
     * @var array<int, RuleResult>
     */
    private array $ruleResults = [];

    private DeveloperTrace $developerTrace;

    private ?int $targetAge;

    public function __construct(
        int $requestId,
        PredictionContext $predictionContext,
        PlaylistBuilder $playlistBuilder,
        DeveloperTrace $developerTrace,
        PredictionConfig $predictionConfig,
        ?CarbonImmutable $currentDate = null,
        ?int $targetAge = null
    ) {
        $this->requestId = $requestId;
        $this->predictionContext = $predictionContext;
        $this->playlistBuilder = $playlistBuilder;
        $this->developerTrace = $developerTrace;
        $this->targetAge = $targetAge;
        $this->currentDate = $currentDate ?? CarbonImmutable::now();
        $this->currentYear = (int) $this->currentDate->format('Y');
        $this->pastYears = $predictionConfig->pastYears();
        $this->futureYears = $predictionConfig->futureYears();

        $this->calculateYears();
    }

    public function getRequestId(): int
    {
        return $this->requestId;
    }

    public function getCurrentDate(): CarbonImmutable
    {
        return $this->currentDate;
    }

    public function getPredictionDate(): CarbonImmutable
    {
        return $this->currentDate;
    }

    public function getCurrentYear(): int
    {
        return $this->currentYear;
    }

    public function getTargetAge(): ?int
    {
        return $this->targetAge;
    }

    public function getPastYears(): int
    {
        return $this->pastYears;
    }

    public function getFutureYears(): int
    {
        return $this->futureYears;
    }

    public function getPastStartYear(): int
    {
        return $this->pastStartYear;
    }

    public function getPastEndYear(): int
    {
        return $this->pastEndYear;
    }

    public function getFutureStartYear(): int
    {
        return $this->futureStartYear;
    }

    public function getFutureEndYear(): int
    {
        return $this->futureEndYear;
    }

    public function getPredictionContext(): PredictionContext
    {
        return $this->predictionContext;
    }

    public function getPlaylistBuilder(): PlaylistBuilder
    {
        return $this->playlistBuilder;
    }

    public function addRuleResult(RuleResult $ruleResult): self
    {
        $this->ruleResults[] = $ruleResult;

        return $this;
    }

    /**
     * @return array<int, RuleResult>
     */
    public function getRuleResults(): array
    {
        return $this->ruleResults;
    }

    public function getDeveloperTrace(): DeveloperTrace
    {
        return $this->developerTrace;
    }

    public function toArray(): array
    {
        return [
            'current_year' => $this->currentYear,
            'past_years' => $this->pastYears,
            'future_years' => $this->futureYears,
            'past_start_year' => $this->pastStartYear,
            'past_end_year' => $this->pastEndYear,
            'future_start_year' => $this->futureStartYear,
            'future_end_year' => $this->futureEndYear,
            'target_age' => $this->targetAge,
        ];
    }

    private function calculateYears(): void
    {
        $this->pastStartYear = $this->currentYear - $this->pastYears - 1;
        $this->pastEndYear = $this->currentYear - 1;
        $this->futureStartYear = $this->currentYear;
        $this->futureEndYear = $this->currentYear + $this->futureYears - 1;
    }
}
