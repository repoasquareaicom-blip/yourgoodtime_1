<?php

namespace App\Services\Prediction;

use Carbon\CarbonInterface;

class PredictionTimelineSummaryService
{
    public function __construct(private readonly PredictionTimelineGenerator $timelineGenerator)
    {
    }

    /**
     * @return array{available:bool,start_year:int|null,end_year:int|null,status:string}
     */
    public function pastRange(CarbonInterface $birthDate, CarbonInterface $predictionDate): array
    {
        $timeline = $this->timelineGenerator->generate('past', $predictionDate, $birthDate);

        return [
            'available' => $timeline['status'] === 'READY',
            'start_year' => $timeline['status'] === 'READY' ? $timeline['from_year'] : null,
            'end_year' => $timeline['status'] === 'READY' ? $timeline['to_year'] : null,
            'status' => $timeline['status'],
        ];
    }
}
