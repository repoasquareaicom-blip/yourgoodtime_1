<?php

namespace App\Services\Prediction;

use App\Services\Prediction\Excel\ExcelReader;

class Rule002WorkbookLookupService
{
    public function __construct(
        private readonly AstrologyNameMapper $nameMapper,
        private readonly AgeWorkbookResolver $workbookResolver,
        private readonly ExcelReader $excelReader
    ) {
    }

    public function findWorksheet(array $worksheets, string $mahadasha): ?array
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

    public function lookupWorkbookMatch(
        string $predictionPeriod,
        int $age,
        AgeWorkbookResult $workbook,
        DashaPeriod $dasha,
        ?string $lagnam,
        ?string $rasi,
        int $position,
        DashaSequenceYearResult $sequenceYear
    ): array {
        $worksheets = $this->excelReader->readWorkbook((string) $workbook->path);
        $worksheet = $this->findWorksheet($worksheets, $dasha->planet);

        if ($worksheet === null) {
            return [
                'status' => 'worksheet_missing',
                'workbook' => $workbook,
                'worksheet' => null,
                'row_matches' => [],
                'matched_row' => null,
                'row_match' => null,
                'year_match' => null,
                'switched_workbook' => false,
            ];
        }

        $rowMatches = $this->matchingRows($worksheet['rows'], $lagnam, $rasi, $position);

        if (count($rowMatches) > 1) {
            return [
                'status' => 'row_ambiguous',
                'workbook' => $workbook,
                'worksheet' => $worksheet,
                'row_matches' => $rowMatches,
                'matched_row' => null,
                'row_match' => null,
                'year_match' => null,
                'switched_workbook' => false,
            ];
        }

        if ($rowMatches === []) {
            return [
                'status' => 'row_missing',
                'workbook' => $workbook,
                'worksheet' => $worksheet,
                'row_matches' => [],
                'matched_row' => null,
                'row_match' => null,
                'year_match' => null,
                'switched_workbook' => false,
            ];
        }

        $matchedRow = (int) array_key_first($rowMatches);
        $rowMatch = $rowMatches[$matchedRow];
        $yearMatch = $this->findYearVideos($rowMatch['row'], $sequenceYear->excelSequenceYear);

        if ($yearMatch === null) {
            return [
                'status' => 'year_missing',
                'workbook' => $workbook,
                'worksheet' => $worksheet,
                'row_matches' => $rowMatches,
                'matched_row' => $matchedRow,
                'row_match' => $rowMatch,
                'year_match' => null,
                'switched_workbook' => false,
            ];
        }

        $nextWorkbookMatch = $this->nextWorkbookRangeVideoMatch(
            predictionPeriod: $predictionPeriod,
            age: $age,
            dasha: $dasha,
            lagnam: $lagnam,
            rasi: $rasi,
            position: $position,
            sequenceYear: $sequenceYear,
            currentWorkbook: $workbook,
            currentYearMatch: $yearMatch
        );

        if ($nextWorkbookMatch !== null) {
            return [
                'status' => 'matched',
                'workbook' => $nextWorkbookMatch['workbook'],
                'worksheet' => $nextWorkbookMatch['worksheet'],
                'row_matches' => $rowMatches,
                'matched_row' => $nextWorkbookMatch['matched_row'],
                'row_match' => [
                    'row' => $nextWorkbookMatch['row'] ?? [],
                    'matched_rasi' => $nextWorkbookMatch['matched_rasi'],
                ],
                'year_match' => $nextWorkbookMatch['year_match'],
                'switched_workbook' => true,
            ];
        }

        return [
            'status' => 'matched',
            'workbook' => $workbook,
            'worksheet' => $worksheet,
            'row_matches' => $rowMatches,
            'matched_row' => $matchedRow,
            'row_match' => $rowMatch,
            'year_match' => $yearMatch,
            'switched_workbook' => false,
        ];
    }

    public function matchingRows(array $rows, ?string $lagnam, ?string $rasi, int $position): array
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
            $isPositionRow = is_numeric($positionCell);
            $isSignCell = $this->isSignCell($firstCell);

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

    public function lagnamBlockHasRasiSections(array $rows, string $expectedLagnam): bool
    {
        $currentLagnam = null;

        foreach ($rows as $row) {
            $firstCell = trim((string) ($row['A'] ?? ''));
            $positionCell = trim((string) ($row['B'] ?? ''));
            $isPositionRow = is_numeric($positionCell);
            $isSignCell = $this->isSignCell($firstCell);
            $firstCellComparison = $this->nameMapper->comparisonValue($firstCell, 'workbook_lagnams');

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

    public function findYearVideos(array $row, int $excelYear): ?array
    {
        foreach ($this->yearVideoGroups($row) as $group) {
            if (! $this->cellContainsYear($group['matched_year'], $excelYear)) {
                continue;
            }

            return [
                'year_column' => $group['year_column'],
                'first_video_column' => $group['first_video_column'],
                'matched_year' => $group['matched_year'],
                'video_ids' => $group['video_ids'],
            ];
        }

        return null;
    }

    public function yearVideoGroups(array $row): array
    {
        $groups = [];
        $columns = array_keys($row);

        foreach ($columns as $index => $column) {
            if ($column === 'A' || $column === 'B') {
                continue;
            }

            if (! $this->cellContainsAnyYear($row[$column] ?? null)) {
                continue;
            }

            $videoColumns = [];

            foreach (array_slice($columns, $index + 1) as $candidateColumn) {
                if ($this->cellContainsAnyYear($row[$candidateColumn] ?? null)) {
                    break;
                }

                $videoColumns[] = $candidateColumn;
            }

            $groups[] = [
                'year_column' => $column,
                'first_video_column' => $videoColumns[0] ?? ($columns[$index + 1] ?? $column),
                'matched_year' => trim((string) ($row[$column] ?? '')),
                'video_columns' => $videoColumns,
                'video_ids' => $this->videoIds($row, $videoColumns),
            ];
        }

        return $groups;
    }

    public function isSignCell(mixed $value): bool
    {
        $firstCell = trim((string) $value);
        $firstCellComparison = $this->nameMapper->comparisonValue($firstCell, 'workbook_lagnams');

        return $firstCell !== ''
            && ! str_contains($firstCellComparison, 'dasa')
            && $this->nameMapper->headerValue($firstCell) !== 'lagnam';
    }

    public function nextWorkbookRangeVideoMatch(
        string $predictionPeriod,
        int $age,
        DashaPeriod $dasha,
        ?string $lagnam,
        ?string $rasi,
        int $position,
        DashaSequenceYearResult $sequenceYear,
        AgeWorkbookResult $currentWorkbook,
        array $currentYearMatch
    ): ?array {
        $range = $this->matchedYearRange((string) ($currentYearMatch['matched_year'] ?? ''));

        if ($range === null) {
            return null;
        }

        [$fromYear, $toYear] = $range;

        if ($fromYear === $toYear || $sequenceYear->excelSequenceYear !== $fromYear) {
            return null;
        }

        $nextWorkbook = $this->workbookResolver->resolve($predictionPeriod, $age + 1);

        if (! $nextWorkbook->found || $nextWorkbook->path === null) {
            return null;
        }

        if ($currentWorkbook->path !== null && realpath($currentWorkbook->path) === realpath($nextWorkbook->path)) {
            return null;
        }

        $worksheets = $this->excelReader->readWorkbook($nextWorkbook->path);
        $worksheet = $this->findWorksheet($worksheets, $dasha->planet);

        if ($worksheet === null) {
            return null;
        }

        $rowMatches = $this->matchingRows($worksheet['rows'], $lagnam, $rasi, $position);

        if (count($rowMatches) !== 1) {
            return null;
        }

        $matchedRow = (int) array_key_first($rowMatches);
        $rowMatch = $rowMatches[$matchedRow];
        $yearMatch = $this->findYearVideos($rowMatch['row'], $sequenceYear->excelSequenceYear);

        if ($yearMatch === null || trim((string) $yearMatch['matched_year']) !== trim((string) $currentYearMatch['matched_year'])) {
            return null;
        }

        if (($yearMatch['video_ids'] ?? []) === []) {
            return null;
        }

        return [
            'workbook' => $nextWorkbook,
            'worksheet' => $worksheet,
            'matched_row' => $matchedRow,
            'row' => $rowMatch['row'],
            'matched_rasi' => $rowMatch['matched_rasi'],
            'year_match' => $yearMatch,
        ];
    }

    public function matchedYearRange(string $value): ?array
    {
        $value = strtolower(preg_replace('/\s+/', ' ', trim($value)));

        if (! preg_match('/^\d{4}(\s*(-|to)\s*\d{4})$/i', $value)) {
            return null;
        }

        preg_match_all('/\d{4}/', $value, $matches);
        $years = array_map('intval', $matches[0] ?? []);

        if (count($years) !== 2 || count(array_filter($years, fn (int $item): bool => $item >= 1900 && $item <= 2100)) !== 2) {
            return null;
        }

        return [min($years), max($years)];
    }

    public function cellContainsYear(mixed $value, int $year): bool
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

    public function cellContainsAnyYear(mixed $value): bool
    {
        $value = trim((string) $value);

        if (preg_match('/^\s*\d{4}(\s*(-|to)\s*\d{4})*\s*$/i', $value) !== 1) {
            return false;
        }

        preg_match_all('/\d{4}/', $value, $matches);
        $years = array_map('intval', $matches[0] ?? []);

        return $years !== [] && count(array_filter($years, fn (int $item): bool => $item >= 1900 && $item <= 2100)) === count($years);
    }

    public function videoIds(array $row, array $videoColumns): array
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
}
