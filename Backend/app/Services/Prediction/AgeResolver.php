<?php

namespace App\Services\Prediction;

use Carbon\CarbonInterface;

class AgeResolver
{
    public function calculateAge(CarbonInterface $birthDate, CarbonInterface $predictionDate): int
    {
        return (int) $birthDate->diffInYears($predictionDate);
    }
}
