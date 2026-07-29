<?php

namespace App\Services\Prediction\Rules;

use App\Services\Prediction\AgeResolver;
use App\Services\Prediction\AgeWorkbookResult;
use App\Services\Prediction\AgeWorkbookResolver;
use App\Services\Prediction\AstrologyNameMapper;
use App\Services\Prediction\BusinessRuleInterface;
use App\Services\Prediction\DashaPeriod;
use App\Services\Prediction\DashaSequenceYearResolver;
use App\Services\Prediction\DashaSequenceYearResult;
use App\Services\Prediction\Excel\ExcelReader;
use App\Services\Prediction\PredictionSession;
use App\Services\Prediction\PredictionYearResolver;
use App\Services\Prediction\RuleResult;
use Illuminate\Support\Facades\Log;
use Throwable;

class Rule002CurrentMahadasha implements BusinessRuleInterface
{
    private const RULE_CODE = 'RULE-002';

    private const RULE_NAME = 'Age Year Mahadasha Prediction';

    public function __construct(
        private readonly ExcelReader $excelReader,
        private readonly AstrologyNameMapper $nameMapper,
        private readonly PredictionYearResolver $yearResolver,
        private readonly DashaSequenceYearResolver $sequenceYearResolver,
        private readonly AgeResolver $ageResolver,
        private readonly AgeWorkbookResolver $workbookResolver
    ) {
    }

    public function execute(PredictionSession $session): RuleResult
    {
        $startedAt = microtime(true);
        $context = $session->getPredictionContext();
        $systemYear = (int) $session->getPredictionDate()->format('Y');
        $yearMetadata = [
            'prediction_date' => (string) $systemYear,
            'calendar_year' => $systemYear,
            'prediction_effective_year' => $systemYear,
            'cutoff_date' => null,
        ];
        $predictionPeriod = strtolower((string) ($context->getRequest()['prediction_type'] ?? 'past'));

        $birthDate = $context->getBirthDate();
        $birthYear = $context->getBirthYear();

        if ($birthDate === null || $birthYear === null) {
            return $this->result($startedAt, 'SKIPPED', $yearMetadata, null, $predictionPeriod, null, null, [], [], 'Birth date is unavailable.');
        }

        $age = $systemYear - $birthYear;
        $dasha = $context->getMahadashaForSystemYear($systemYear);
        $this->logLookup('system year resolved', compact('age', 'systemYear', 'birthYear', 'predictionPeriod'));

        if ($dasha === null) {
            return $this->result($startedAt, 'NO_MATCH', $yearMetadata, $age, $predictionPeriod, null, null, [], [], 'Mahadasha data is unavailable for system year.');
        }

        try {
            $sequenceYear = $this->sequenceYearResolver->resolveForSystemYear($systemYear, $dasha->startDate, $dasha->endDate);
            $this->logLookup('excel sequence year resolved', [
                'major_dasha' => $dasha->planet,
                'dasha_start_year' => $sequenceYear->dashaStartEffectiveYear,
                'system_year' => $systemYear,
                'excel_sequence_year' => $sequenceYear->excelSequenceYear,
            ]);
        } catch (Throwable $exception) {
            return $this->result($startedAt, 'ERROR', $yearMetadata, $age, $predictionPeriod, null, null, [], [], $exception->getMessage());
        }

        if (! $sequenceYear->isWithinPeriod) {
            return $this->result($startedAt, 'NO_MATCH', $yearMetadata, $age, $predictionPeriod, null, null, [], [], $sequenceYear->remarks, sequenceYear: $sequenceYear);
        }

        $planetHousePosition = $context->getPlanetHousePosition($dasha->planet);

        $identifiedValues = $this->identifiedValues(
            $yearMetadata,
            $age,
            $predictionPeriod,
            $dasha,
            $sequenceYear,
            $planetHousePosition,
            $context->getLagnam(),
            $context->getRasi()
        );

        if ($context->getLagnam() === null) {
            return $this->result($startedAt, 'SKIPPED', $yearMetadata, $age, $predictionPeriod, null, null, $identifiedValues, [], 'Lagnam is unavailable.');
        }

        if ($context->getRasi() === null) {
            return $this->result($startedAt, 'SKIPPED', $yearMetadata, $age, $predictionPeriod, null, null, $identifiedValues, [], 'Rasi is unavailable.');
        }

        if ($planetHousePosition === null) {
            return $this->result($startedAt, 'SKIPPED', $yearMetadata, $age, $predictionPeriod, null, null, $identifiedValues, [], 'Planet house position is unavailable.');
        }

        $workbook = $this->workbookResolver->resolve($predictionPeriod, $age);
        $matchedValues = $this->matchedValues($workbook, null, $context->getLagnam(), $context->getRasi(), $planetHousePosition, $sequenceYear);
        $this->logLookup('age workbook resolved', [
            'age' => $age,
            'workbook' => $workbook->fileName,
            'found' => $workbook->found,
            'remarks' => $workbook->remarks,
        ]);

        if (! $workbook->found || $workbook->path === null) {
            return $this->result($startedAt, 'NO_MATCH', $yearMetadata, $age, $predictionPeriod, $workbook, null, $identifiedValues, $matchedValues, $workbook->remarks, sequenceYear: $sequenceYear);
        }

        try {
            $worksheets = $this->excelReader->readWorkbook($workbook->path);
            $worksheet = $this->findWorksheet($worksheets, $dasha->planet);

            if ($worksheet === null) {
                return $this->result($startedAt, 'NO_MATCH', $yearMetadata, $age, $predictionPeriod, $workbook, null, $identifiedValues, $matchedValues, 'No matching Mahadasha worksheet found.', sequenceYear: $sequenceYear);
            }

            $matchedValues = $this->matchedValues($workbook, $worksheet['worksheet'], $context->getLagnam(), $context->getRasi(), $planetHousePosition, $sequenceYear);
            $rowMatches = $this->matchingRows($worksheet['rows'], $context->getLagnam(), $context->getRasi(), $planetHousePosition);
            $this->logLookup('worksheet row lookup completed', [
                'sheet' => $worksheet['worksheet'],
                'lagnam' => $context->getLagnam(),
                'rasi' => $context->getRasi(),
                'position' => $planetHousePosition,
                'matches' => array_keys($rowMatches),
            ]);

            if (count($rowMatches) > 1) {
                return $this->result($startedAt, 'ERROR', $yearMetadata, $age, $predictionPeriod, $workbook, $worksheet['worksheet'], $identifiedValues, $matchedValues, 'Ambiguous matching rows: '.implode(', ', array_keys($rowMatches)), sequenceYear: $sequenceYear);
            }

            if ($rowMatches === []) {
                return $this->result($startedAt, 'NO_MATCH', $yearMetadata, $age, $predictionPeriod, $workbook, $worksheet['worksheet'], $identifiedValues, $matchedValues, 'No matching Lagnam, Rasi and position row found.', sequenceYear: $sequenceYear);
            }

            $matchedRow = (int) array_key_first($rowMatches);
            $rowMatch = $rowMatches[$matchedRow];
            $row = $rowMatch['row'];
            $matchedValues['rasi'] = $rowMatch['matched_rasi'];
            $yearMatch = $this->findYearVideos($row, $sequenceYear->excelSequenceYear);

            if ($yearMatch === null) {
                return $this->result($startedAt, 'NO_MATCH', $yearMetadata, $age, $predictionPeriod, $workbook, $worksheet['worksheet'], $identifiedValues, $matchedValues, 'Excel sequence year column not found.', $matchedRow, sequenceYear: $sequenceYear);
            }

            $matchedValues['matched_year'] = $yearMatch['matched_year'];
            $matchedValues['video_ids'] = $yearMatch['video_ids'];
            $this->logLookup('year/video lookup completed', [
                'excel_sequence_year' => $sequenceYear->excelSequenceYear,
                'matched_year' => $yearMatch['matched_year'],
                'video_ids' => $yearMatch['video_ids'],
            ]);

            if ($yearMatch['video_ids'] === []) {
                return $this->result($startedAt, 'NO_MATCH', $yearMetadata, $age, $predictionPeriod, $workbook, $worksheet['worksheet'], $identifiedValues, $matchedValues, 'Video value not found for Excel sequence year.', $matchedRow, $yearMatch['year_column'], $yearMatch['first_video_column'], $yearMatch['first_video_column'].$matchedRow, sequenceYear: $sequenceYear);
            }

            foreach ($yearMatch['video_ids'] as $videoId) {
                $session->getPlaylistBuilder()->addVideo(
                    $videoId,
                    self::RULE_CODE,
                    self::RULE_NAME,
                    $identifiedValues,
                    $matchedValues
                );
            }

            return $this->result(
                startedAt: $startedAt,
                status: 'SUCCESS',
                yearMetadata: $yearMetadata,
                age: $age,
                predictionPeriod: $predictionPeriod,
                workbook: $workbook,
                worksheet: $worksheet['worksheet'],
                identifiedValues: $identifiedValues,
                matchedValues: $matchedValues,
                remarks: 'Matching row and year found.',
                matchedRow: $matchedRow,
                yearColumn: $yearMatch['year_column'],
                videoColumn: $yearMatch['first_video_column'],
                matchedCell: $yearMatch['first_video_column'].$matchedRow,
                resultValue: $yearMatch['video_ids'],
                sequenceYear: $sequenceYear
            );
        } catch (Throwable $exception) {
            return $this->result($startedAt, 'ERROR', $yearMetadata, $age, $predictionPeriod, $workbook, null, $identifiedValues, $matchedValues, $exception->getMessage(), sequenceYear: $sequenceYear);
        }
    }

    private function identifiedValues(array $yearMetadata, int $age, string $predictionPeriod, DashaPeriod $dasha, DashaSequenceYearResult $sequenceYear, ?int $planetHousePosition, ?string $lagnam, ?string $rasi): array
    {
        return [
            'prediction_date' => $yearMetadata['prediction_date'],
            'calendar_year' => $yearMetadata['calendar_year'],
            'prediction_effective_year' => $yearMetadata['prediction_effective_year'],
            'year_cutoff' => $yearMetadata['cutoff_date'],
            'age' => $age,
            'system_year' => $yearMetadata['calendar_year'],
            'prediction_period' => $predictionPeriod,
            'mahadasha' => $dasha->planet,
            'mahadasha_start_date' => $dasha->startDate,
            'mahadasha_end_date' => $dasha->endDate,
            'dasha_start_effective_year' => $sequenceYear->dashaStartEffectiveYear,
            'dasha_end_effective_year' => $sequenceYear->dashaEndEffectiveYear,
            'planet_id' => $dasha->planetId ?? $this->nameMapper->planetId($dasha->planet),
            'planet_name' => $dasha->planet,
            'planet_house_position' => $planetHousePosition,
            'lagnam' => $lagnam,
            'rasi' => $rasi,
        ];
    }

    private function matchedValues(AgeWorkbookResult $workbook, ?string $worksheet, ?string $lagnam, ?string $rasi, ?int $position, DashaSequenceYearResult $sequenceYear): array
    {
        return [
            'workbook' => $workbook->fileName,
            'worksheet' => $worksheet,
            'lagnam' => $this->nameMapper->excelValue($lagnam, 'workbook_lagnams'),
            'rasi' => $this->nameMapper->excelValue($rasi, 'workbook_lagnams'),
            'position' => $position,
            'sequence_base_year' => $sequenceYear->sequenceBaseYear,
            'sequence_offset' => $sequenceYear->sequenceOffset,
            'dasha_year_number' => $sequenceYear->dashaYearNumber,
            'excel_sequence_year' => $sequenceYear->excelSequenceYear,
            'supported_age_from' => $workbook->supportedFromAge,
            'supported_age_to' => $workbook->supportedToAge,
        ];
    }

    private function findWorksheet(array $worksheets, string $mahadasha): ?array
    {
        $expected = $this->nameMapper->comparisonValue($mahadasha, 'dasha_sheets');

        foreach ($worksheets as $worksheet) {
            $actual = $this->nameMapper->comparisonValue($worksheet['worksheet'] ?? '', 'dasha_sheets');

            if ($actual === $expected) {
                return $worksheet;
            }
        }

        return null;
    }

    private function matchingRows(array $rows, ?string $lagnam, ?string $rasi, int $position): array
    {
        $matches = [];
        $expectedLagnam = $this->nameMapper->comparisonValue($lagnam, 'workbook_lagnams');
        $expectedRasi = $this->nameMapper->comparisonValue($rasi, 'workbook_lagnams');
        $expectedRasiLabel = $this->nameMapper->excelValue($rasi, 'workbook_lagnams');
        $expectedBlockHasRasiSections = $this->lagnamBlockHasRasiSections($rows, $expectedLagnam);
        $currentLagnam = null;
        $currentRasi = null;

        foreach ($rows as $rowNumber => $row) {
            $firstCell = trim((string) ($row['A'] ?? ''));
            $positionCell = trim((string) ($row['B'] ?? ''));
            $firstCellComparison = $this->nameMapper->comparisonValue($firstCell, 'workbook_lagnams');
            $isPositionRow = is_numeric($positionCell);
            $isSignCell = $firstCell !== ''
                && ! str_contains($firstCellComparison, 'dasa')
                && $this->nameMapper->headerValue($firstCell) !== 'lagnam';

            if ($isSignCell && ! $isPositionRow) {
                $currentLagnam = $firstCell;
                $currentRasi = null;
            }

            if (! $isPositionRow) {
                continue;
            }

            if (! $expectedBlockHasRasiSections && $isSignCell) {
                $currentLagnam = $firstCell;
                $currentRasi = null;
            }

            $insideExpectedLagnam = $currentLagnam !== null
                && $this->nameMapper->comparisonValue($currentLagnam, 'workbook_lagnams') === $expectedLagnam;

            if ($insideExpectedLagnam && $firstCell !== '') {
                $currentRasi = $firstCell;
            }

            if ((int) $positionCell !== $position) {
                continue;
            }

            if ($insideExpectedLagnam && $this->nameMapper->comparisonValue($currentRasi, 'workbook_lagnams') === $expectedRasi) {
                $matches[(int) $rowNumber] = [
                    'row' => $row,
                    'matched_rasi' => $this->nameMapper->excelValue($currentRasi, 'workbook_lagnams'),
                ];

                continue;
            }

            if (! $expectedBlockHasRasiSections && $insideExpectedLagnam) {
                $matches[(int) $rowNumber] = [
                    'row' => $row,
                    'matched_rasi' => $expectedRasiLabel,
                ];
            }
        }

        return $matches;
    }

    private function lagnamBlockHasRasiSections(array $rows, string $expectedLagnam): bool
    {
        $currentLagnam = null;

        foreach ($rows as $row) {
            $firstCell = trim((string) ($row['A'] ?? ''));
            $positionCell = trim((string) ($row['B'] ?? ''));
            $firstCellComparison = $this->nameMapper->comparisonValue($firstCell, 'workbook_lagnams');
            $isPositionRow = is_numeric($positionCell);
            $isSignCell = $firstCell !== ''
                && ! str_contains($firstCellComparison, 'dasa')
                && $this->nameMapper->headerValue($firstCell) !== 'lagnam';

            if ($isSignCell && ! $isPositionRow) {
                $currentLagnam = $firstCell;

                continue;
            }

            $insideExpectedLagnam = $currentLagnam !== null
                && $this->nameMapper->comparisonValue($currentLagnam, 'workbook_lagnams') === $expectedLagnam;

            if ($insideExpectedLagnam && $isSignCell && $isPositionRow && $firstCellComparison !== $expectedLagnam) {
                return true;
            }
        }

        return false;
    }

    private function findYearVideos(array $row, int $excelYear): ?array
    {
        $columns = array_keys($row);

        foreach ($columns as $index => $column) {
            if ($column === 'A' || $column === 'B') {
                continue;
            }

            if (! $this->cellContainsYear($row[$column] ?? null, $excelYear)) {
                continue;
            }

            $videoColumns = [];

            foreach (array_slice($columns, $index + 1) as $candidateColumn) {
                if ($this->cellContainsAnyYear($row[$candidateColumn] ?? null)) {
                    break;
                }

                $videoColumns[] = $candidateColumn;
            }

            if ($videoColumns === []) {
                return [
                    'year_column' => $column,
                    'first_video_column' => $columns[$index + 1] ?? $column,
                    'matched_year' => trim((string) ($row[$column] ?? '')),
                    'video_ids' => [],
                ];
            }

            return [
                'year_column' => $column,
                'first_video_column' => $videoColumns[0],
                'matched_year' => trim((string) ($row[$column] ?? '')),
                'video_ids' => $this->videoIds($row, $videoColumns),
            ];
        }

        return null;
    }

    private function cellContainsYear(mixed $value, int $year): bool
    {
        $value = strtolower(preg_replace('/\s+/', ' ', trim((string) $value)));

        if (! preg_match('/^\d{4}(\s*(-|to)\s*\d{4})*$/i', $value)) {
            return false;
        }

        preg_match_all('/\d{4}/', $value, $matches);

        $years = array_map('intval', $matches[0] ?? []);

        if ($years === [] || count(array_filter($years, fn (int $item): bool => $item >= 1900 && $item <= 2100)) !== count($years)) {
            return false;
        }

        if (count($years) >= 2) {
            return $year >= min($years) && $year <= max($years);
        }

        return in_array($year, $years, true);
    }

    private function cellContainsAnyYear(mixed $value): bool
    {
        $value = trim((string) $value);

        if (preg_match('/^\s*\d{4}(\s*(-|to)\s*\d{4})*\s*$/i', $value) !== 1) {
            return false;
        }

        preg_match_all('/\d{4}/', $value, $matches);
        $years = array_map('intval', $matches[0] ?? []);

        return $years !== [] && count(array_filter($years, fn (int $item): bool => $item >= 1900 && $item <= 2100)) === count($years);
    }

    private function videoIds(array $row, array $videoColumns): array
    {
        $videoIds = [];

        foreach ($videoColumns as $column) {
            foreach (preg_split('/[\s,]+/', trim((string) ($row[$column] ?? ''))) ?: [] as $videoId) {
                $videoId = trim($videoId);

                if ($videoId !== '') {
                    $videoIds[] = $videoId;
                }
            }
        }

        return array_values(array_unique($videoIds));
    }

    private function logLookup(string $message, array $context): void
    {
        Log::info('Prediction Rule002 lookup: '.$message, $context);
    }

    private function result(
        float $startedAt,
        string $status,
        array $yearMetadata,
        ?int $age,
        ?string $predictionPeriod,
        ?AgeWorkbookResult $workbook,
        ?string $worksheet,
        array $identifiedValues,
        array $matchedValues,
        ?string $remarks = null,
        null|int|string $matchedRow = null,
        null|int|string $yearColumn = null,
        null|int|string $videoColumn = null,
        null|int|string $matchedCell = null,
        mixed $resultValue = null,
        ?DashaSequenceYearResult $sequenceYear = null
    ): RuleResult {
        return new RuleResult(
            ruleCode: self::RULE_CODE,
            ruleName: self::RULE_NAME,
            status: $status,
            executionTimeMs: $this->executionTimeMs($startedAt),
            excelFile: $workbook?->fileName,
            worksheet: $worksheet,
            lookupValues: $identifiedValues,
            matchedValues: $matchedValues,
            identifiedValues: $identifiedValues,
            predictionDate: $yearMetadata['prediction_date'] ?? null,
            calendarYear: $yearMetadata['calendar_year'] ?? null,
            excelYear: $sequenceYear?->excelSequenceYear,
            predictionEffectiveYear: $yearMetadata['prediction_effective_year'] ?? null,
            dashaStartDate: $sequenceYear?->dashaStartDate,
            dashaStartEffectiveYear: $sequenceYear?->dashaStartEffectiveYear,
            dashaEndDate: $sequenceYear?->dashaEndDate,
            dashaEndEffectiveYear: $sequenceYear?->dashaEndEffectiveYear,
            sequenceBaseYear: $sequenceYear?->sequenceBaseYear,
            sequenceOffset: $sequenceYear?->sequenceOffset,
            dashaYearNumber: $sequenceYear?->dashaYearNumber,
            excelSequenceYear: $sequenceYear?->excelSequenceYear,
            cutoffDate: $yearMetadata['cutoff_date'] ?? null,
            age: $age,
            predictionPeriod: $predictionPeriod,
            matchedRow: $matchedRow,
            yearColumn: $yearColumn,
            videoColumn: $videoColumn,
            matchedColumn: $videoColumn,
            matchedCell: $matchedCell,
            resultValue: $resultValue,
            remarks: $remarks
        );
    }

    private function executionTimeMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
