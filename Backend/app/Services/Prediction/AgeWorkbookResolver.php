<?php

namespace App\Services\Prediction;

use InvalidArgumentException;
use Illuminate\Support\Str;

class AgeWorkbookResolver
{
    public function __construct(private readonly PredictionConfig $predictionConfig)
    {
    }

    public function resolve(string $predictionPeriod, int $age): AgeWorkbookResult
    {
        $period = strtolower(trim($predictionPeriod));
        $directory = $this->resolveExcelDirectory($period);

        if (! is_dir($directory)) {
            return new AgeWorkbookResult(false, null, null, $period, remarks: "Workbook directory not found: {$directory}");
        }

        $matches = [];
        $supportedFromAge = null;
        $supportedToAge = null;

        foreach (glob($directory.DIRECTORY_SEPARATOR.'*.xlsx') ?: [] as $path) {
            $fileName = basename($path);

            if (Str::startsWith($fileName, '~$')) {
                continue;
            }

            $range = $this->ageRange($fileName);

            if ($range === null) {
                continue;
            }

            [$fromAge, $toAge] = $range;
            $supportedFromAge = $supportedFromAge === null ? $fromAge : min($supportedFromAge, $fromAge);
            $supportedToAge = $supportedToAge === null ? $toAge : max($supportedToAge, $toAge);

            if ($age >= $fromAge && $age <= $toAge) {
                $matches[] = new AgeWorkbookResult(true, $path, $fileName, $period, $fromAge, $toAge, $supportedFromAge, $supportedToAge);
            }
        }

        if (count($matches) === 1) {
            $match = $matches[0];

            return new AgeWorkbookResult(true, $match->path, $match->fileName, $match->period, $match->fromAge, $match->toAge, $supportedFromAge, $supportedToAge);
        }

        if (count($matches) > 1) {
            return new AgeWorkbookResult(false, null, null, $period, supportedFromAge: $supportedFromAge, supportedToAge: $supportedToAge, remarks: 'Multiple age workbooks matched.');
        }

        return new AgeWorkbookResult(false, null, null, $period, supportedFromAge: $supportedFromAge, supportedToAge: $supportedToAge, remarks: "No {$period} workbook covers age {$age}.");
    }

    public function resolveExcelDirectory(string $predictionType): string
    {
        $period = strtolower(trim($predictionType));

        if (! in_array($period, ['past', 'future'], true)) {
            throw new InvalidArgumentException("Unsupported prediction workbook period: {$predictionType}");
        }

        return rtrim($this->predictionConfig->excelPath(), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR
            .$period;
    }

    private function ageRange(string $fileName): ?array
    {
        $name = pathinfo($fileName, PATHINFO_FILENAME);
        $name = preg_replace('/^future[_\s-]*/i', '', trim($name));
        $name = preg_replace('/\s+/', ' ', (string) $name);

        if (preg_match('/^(\d+)$/', $name, $matches)) {
            $age = (int) $matches[1];

            return [$age, $age];
        }

        if (preg_match('/^(\d+)\s*to\s*(\d+)$/i', $name, $matches)) {
            return [(int) $matches[1], (int) $matches[2]];
        }

        return null;
    }
}
