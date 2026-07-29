<?php

namespace App\Services\Prediction;

class DashaPeriod
{
    public function __construct(
        public readonly string $planet,
        public readonly ?int $planetId,
        public readonly string $startDate,
        public readonly string $endDate
    ) {
    }

    public function toArray(): array
    {
        return [
            'planet' => $this->planet,
            'planet_id' => $this->planetId,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
        ];
    }
}
