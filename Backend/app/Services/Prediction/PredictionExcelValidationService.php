<?php

namespace App\Services\Prediction;

use App\Services\Prediction\Excel\ExcelReader;
use Throwable;

class PredictionExcelValidationService
{
    public function __construct(
        private readonly ExcelReader $excelReader,
        private readonly PredictionConfig $predictionConfig,
        private readonly AstrologyNameMapper $nameMapper,
        private readonly AgeWorkbookResolver $ageWorkbookResolver,
        private readonly Rule002WorkbookLookupService $rule002Lookup
    ) {
    }

    public function validateNakshatraLagnam(): array
    {
        $fileName = $this->predictionConfig->predictionFile('nakshatra_lagnam');

        $path = rtrim(
            $this->predictionConfig->excelPath(),
            DIRECTORY_SEPARATOR
        ).DIRECTORY_SEPARATOR.$fileName;

        try {
            $workbook = $this->excelReader->readFirstWorksheet($path);
        } catch (Throwable $exception) {
            return [
                'success' => false,
                'valid' => false,
                'file' => $fileName,
                'message' => $exception->getMessage(),
                'summary' => null,
                'issues' => [],
            ];
        }

        $sheet = $workbook['worksheet'];
        $rows = $workbook['rows'];

        $headers = $this->headers($rows);

        $columns = [
            'lagnam' => $headers['lagnam'] ?? null,
            'rasi' => $headers['rasi'] ?? null,
            'nakshatra' => $headers['nakshatra'] ?? null,
            'video_id' => $headers['videoid']
                ?? $headers['video_id']
                ?? $headers['video']
                ?? null,
        ];

        $missingColumns = [];

        foreach ($columns as $name => $column) {
            if ($column === null) {
                $missingColumns[] = $name;
            }
        }

        if ($missingColumns !== []) {
            return [
                'success' => true,
                'valid' => false,
                'file' => $fileName,
                'sheet' => $sheet,
                'message' => 'Required columns are missing.',
                'summary' => [
                    'total_rows' => max(count($rows) - 1, 0),
                    'valid_rows' => 0,
                    'issue_rows' => 0,
                    'mapping_mismatches' => 0,
                    'blank_required_fields' => 0,
                    'missing_video_ids' => 0,
                    'duplicate_combinations' => 0,
                ],
                'missing_columns' => $missingColumns,
                'issues' => [],
            ];
        }

        /*
         * These are the canonical values that the current prediction
         * application can produce.
         */
        $validSigns = [
            'Aries',
            'Taurus',
            'Gemini',
            'Cancer',
            'Leo',
            'Virgo',
            'Libra',
            'Scorpio',
            'Sagittarius',
            'Capricorn',
            'Aquarius',
            'Pisces',
        ];

        /*
        * Canonical Nakshatra names that the astrology system can produce.
        */
        $validNakshatras = config(
            'prediction.canonical_names.nakshatras',
            []
        );

        $validLagnamValues = $this->canonicalValues(
            $validSigns,
            'workbook_lagnams'
        );

        $validRasiValues = $validLagnamValues;

        $validNakshatraValues = $this->canonicalValues(
            $validNakshatras,
            'nakshatras'
        );

        $issues = [];
        $validRows = 0;
        $issueRows = 0;
        $mappingMismatches = 0;
        $blankRequiredFields = 0;
        $missingVideoIds = 0;
        $duplicateCombinations = 0;

        $uniqueLagnams = [];
        $uniqueRasis = [];
        $uniqueNakshatras = [];
        $seenCombinations = [];

        foreach ($rows as $rowNumber => $row) {
            if ((int) $rowNumber === 1) {
                continue;
            }

            $rowIssues = [];

            $lagnam = trim((string) (
                $row[$columns['lagnam']] ?? ''
            ));

            $rasi = trim((string) (
                $row[$columns['rasi']] ?? ''
            ));

            $nakshatra = trim((string) (
                $row[$columns['nakshatra']] ?? ''
            ));

            $videoId = trim((string) (
                $row[$columns['video_id']] ?? ''
            ));

            /*
             * Completely empty rows are ignored.
             */
            if (
                $lagnam === ''
                && $rasi === ''
                && $nakshatra === ''
                && $videoId === ''
            ) {
                continue;
            }

            if ($lagnam === '') {
                $blankRequiredFields++;

                $rowIssues[] = $this->issue(
                    $rowNumber,
                    'Lagnam',
                    $lagnam,
                    '',
                    'Blank required value'
                );
            } else {
                $normalized = $this->nameMapper->comparisonValue(
                    $lagnam,
                    'workbook_lagnams'
                );

                $uniqueLagnams[$normalized] = $lagnam;

                if (! isset($validLagnamValues[$normalized])) {
                    $mappingMismatches++;

                    $rowIssues[] = $this->issue(
                        $rowNumber,
                        'Lagnam',
                        $lagnam,
                        $normalized,
                        'Unknown or unmapped Lagnam value'
                    );
                }
            }

            if ($rasi === '') {
                $blankRequiredFields++;

                $rowIssues[] = $this->issue(
                    $rowNumber,
                    'Rasi',
                    $rasi,
                    '',
                    'Blank required value'
                );
            } else {
                $normalized = $this->nameMapper->comparisonValue(
                    $rasi,
                    'workbook_lagnams'
                );

                $uniqueRasis[$normalized] = $rasi;

                if (! isset($validRasiValues[$normalized])) {
                    $mappingMismatches++;

                    $rowIssues[] = $this->issue(
                        $rowNumber,
                        'Rasi',
                        $rasi,
                        $normalized,
                        'Unknown or unmapped Rasi value'
                    );
                }
            }

            if ($nakshatra === '') {
                $blankRequiredFields++;

                $rowIssues[] = $this->issue(
                    $rowNumber,
                    'Nakshatra',
                    $nakshatra,
                    '',
                    'Blank required value'
                );
            } else {
                $normalized = $this->nameMapper->comparisonValue(
                    $nakshatra,
                    'nakshatras'
                );

                $uniqueNakshatras[$normalized] = $nakshatra;

                if (! isset($validNakshatraValues[$normalized])) {
                    $mappingMismatches++;

                    $rowIssues[] = $this->issue(
                        $rowNumber,
                        'Nakshatra',
                        $nakshatra,
                        $normalized,
                        'Unknown or unmapped Nakshatra value'
                    );
                }
            }

            if ($videoId === '') {
                $missingVideoIds++;

                $rowIssues[] = $this->issue(
                    $rowNumber,
                    'Video',
                    '',
                    '',
                    'Missing Video ID'
                );
            }

            if (
                $lagnam !== ''
                && $rasi !== ''
                && $nakshatra !== ''
            ) {
                $combination = implode('|', [
                    $this->nameMapper->comparisonValue(
                        $lagnam,
                        'workbook_lagnams'
                    ),
                    $this->nameMapper->comparisonValue(
                        $rasi,
                        'workbook_lagnams'
                    ),
                    $this->nameMapper->comparisonValue(
                        $nakshatra,
                        'nakshatras'
                    ),
                ]);

                if (isset($seenCombinations[$combination])) {
                    $duplicateCombinations++;

                    $rowIssues[] = $this->issue(
                        $rowNumber,
                        'Combination',
                        "{$lagnam} / {$rasi} / {$nakshatra}",
                        '',
                        'Duplicate combination; first found at row '
                            .$seenCombinations[$combination]
                    );
                } else {
                    $seenCombinations[$combination] = $rowNumber;
                }
            }

            if ($rowIssues === []) {
                $validRows++;
            } else {
                $issueRows++;

                foreach ($rowIssues as $issue) {
                    $issues[] = $issue;
                }
            }
        }

        $totalRows = $validRows + $issueRows;

        return [
            'success' => true,
            'valid' => $issues === [],
            'file' => $fileName,
            'sheet' => $sheet,
            'message' => $issues === []
                ? 'Validation passed. No problems found.'
                : 'Validation completed with issues.',
            'summary' => [
                'total_rows' => $totalRows,
                'valid_rows' => $validRows,
                'issue_rows' => $issueRows,

                'unique_lagnams' => count($uniqueLagnams),
                'unique_rasis' => count($uniqueRasis),
                'unique_nakshatras' => count($uniqueNakshatras),

                'mapping_mismatches' => $mappingMismatches,
                'blank_required_fields' => $blankRequiredFields,
                'missing_video_ids' => $missingVideoIds,
                'duplicate_combinations' => $duplicateCombinations,
            ],
            'issues' => $issues,
        ];
    }

    public function validateRule002PastWorkbooks(?string $onlyWorkbook = null): array
    {
        $period = 'past';

        try {
            $directory = $this->ageWorkbookResolver->resolveExcelDirectory($period);
        } catch (Throwable $exception) {
            return $this->rule002ErrorResponse($period, $exception->getMessage());
        }

        if (! is_dir($directory)) {
            return $this->rule002ErrorResponse($period, "Workbook directory not found: {$directory}");
        }

        $paths = glob($directory.DIRECTORY_SEPARATOR.'*.xlsx') ?: [];
        natcasesort($paths);
        $paths = array_values($paths);

        if ($onlyWorkbook !== null && trim($onlyWorkbook) !== '') {
            $paths = array_values(array_filter(
                $paths,
                fn (string $path): bool => basename($path) === trim($onlyWorkbook)
            ));
        }

        $requiredSheets = $this->requiredRule002DashaSheets();
        $validLagnams = $this->validWorkbookLagnamValues();
        $workbooks = [];
        $issues = [];
        $rangeRecords = [];

        foreach ($paths as $path) {
            $fileName = basename($path);

            if (str_starts_with($fileName, '~$')) {
                continue;
            }

            $range = $this->ageWorkbookResolver->parseAgeRange($fileName);
            $workbookResult = $this->emptyRule002WorkbookResult($fileName, $period, $range);

            if ($range === null) {
                $this->addRule002Issue(
                    $workbookResult,
                    $issues,
                    null,
                    0,
                    'Workbook filename',
                    $fileName,
                    '',
                    'Workbook filename does not contain a valid Rule002 age range.'
                );
                $workbooks[] = $workbookResult;

                continue;
            }

            [$fromAge, $toAge] = $range;
            $rangeRecords[] = [
                'file' => $fileName,
                'from' => $fromAge,
                'to' => $toAge,
            ];

            $limits = $this->predictionConfig->ageLimits($period);

            if ($fromAge > $toAge || $fromAge < $limits['minimum'] || $toAge > $limits['maximum']) {
                $this->addRule002Issue(
                    $workbookResult,
                    $issues,
                    null,
                    0,
                    'Age range',
                    "{$fromAge}-{$toAge}",
                    "{$fromAge}-{$toAge}",
                    "Workbook age range must be inside configured {$period} limits {$limits['minimum']}-{$limits['maximum']}."
                );
            }

            try {
                $worksheets = $this->excelReader->readWorkbook($path);
            } catch (Throwable $exception) {
                $this->addRule002Issue(
                    $workbookResult,
                    $issues,
                    null,
                    0,
                    'Workbook',
                    $fileName,
                    '',
                    $exception->getMessage()
                );
                $workbooks[] = $workbookResult;

                continue;
            }

            $normalizedSheets = [];

            foreach ($worksheets as $worksheet) {
                $sheetName = (string) ($worksheet['worksheet'] ?? '');
                $normalizedSheet = $this->nameMapper->comparisonValue($sheetName, 'dasha_sheets');

                if (! isset($requiredSheets[$normalizedSheet])) {
                    $this->addRule002Issue(
                        $workbookResult,
                        $issues,
                        $sheetName,
                        0,
                        'Worksheet',
                        $sheetName,
                        $normalizedSheet,
                        'Worksheet name is not mapped to a required Rule002 Dasha sheet.'
                    );
                }

                if (isset($normalizedSheets[$normalizedSheet])) {
                    $this->addRule002Issue(
                        $workbookResult,
                        $issues,
                        $sheetName,
                        0,
                        'Worksheet',
                        $sheetName,
                        $normalizedSheet,
                        'Duplicate normalized Dasha worksheet; first found as '.$normalizedSheets[$normalizedSheet]
                    );
                } else {
                    $normalizedSheets[$normalizedSheet] = $sheetName;
                }

                if (isset($requiredSheets[$normalizedSheet])) {
                    $this->validateRule002PastWorksheet(
                        $workbookResult,
                        $issues,
                        $sheetName,
                        $worksheet['rows'] ?? [],
                        $validLagnams
                    );
                }
            }

            foreach ($requiredSheets as $normalizedSheet => $label) {
                if (! isset($normalizedSheets[$normalizedSheet])) {
                    $this->addRule002Issue(
                        $workbookResult,
                        $issues,
                        null,
                        0,
                        'Worksheet',
                        '',
                        $normalizedSheet,
                        "Required Dasha worksheet {$label} is missing."
                    );
                }
            }

            $workbooks[] = $workbookResult;
        }

        $this->validateRule002AgeRangeSet($workbooks, $issues, $rangeRecords, $period);
        $summary = $this->rule002Summary($workbooks);

        return [
            'success' => true,
            'valid' => $issues === [],
            'validator' => 'rule002_past_workbooks',
            'period' => $period,
            'message' => $issues === []
                ? 'Validation passed. No problems found.'
                : 'Validation completed with issues.',
            'summary' => $summary,
            'workbooks' => array_values($workbooks),
            'issues' => array_values($issues),
        ];
    }

    private function validateRule002PastWorksheet(
        array &$workbookResult,
        array &$issues,
        string $worksheet,
        array $rows,
        array $validLagnams
    ): void {
        $workbookResult['worksheets_checked']++;

        $currentLagnam = null;
        $positionsByLagnam = [];

        foreach ($rows as $rowNumber => $row) {
            $firstCell = trim((string) ($row['A'] ?? ''));
            $positionCell = trim((string) ($row['B'] ?? ''));
            $isPositionRow = is_numeric($positionCell);
            $isSignCell = $this->rule002Lookup->isSignCell($firstCell);
            $rowIssuesBefore = count($issues);

            if ($isSignCell && ! $isPositionRow) {
                $normalized = $this->nameMapper->comparisonValue($firstCell, 'workbook_lagnams');
                $currentLagnam = $firstCell;
                $positionsByLagnam[$normalized] ??= [];

                if (! isset($validLagnams[$normalized])) {
                    $this->addRule002Issue(
                        $workbookResult,
                        $issues,
                        $worksheet,
                        $rowNumber,
                        'Lagnam',
                        $firstCell,
                        $normalized,
                        'Unknown or unmapped Lagnam block.'
                    );
                }

                continue;
            }

            if (! $isPositionRow) {
                if ($this->rowHasRule002Data($row)) {
                    $this->addRule002Issue(
                        $workbookResult,
                        $issues,
                        $worksheet,
                        $rowNumber,
                        'Position',
                        $positionCell,
                        '',
                        'Structural row problem: row contains Rule002 data without a numeric position.'
                    );
                }

                continue;
            }

            $workbookResult['total_rows']++;

            if ($isSignCell) {
                $currentLagnam = $firstCell;
            }

            $normalizedLagnam = $this->nameMapper->comparisonValue($currentLagnam, 'workbook_lagnams');

            if ($currentLagnam === null || $currentLagnam === '') {
                $this->addRule002Issue(
                    $workbookResult,
                    $issues,
                    $worksheet,
                    $rowNumber,
                    'Lagnam',
                    $firstCell,
                    '',
                    'Structural row problem: position row appears before a Lagnam block.'
                );
            } elseif (! isset($validLagnams[$normalizedLagnam])) {
                $this->addRule002Issue(
                    $workbookResult,
                    $issues,
                    $worksheet,
                    $rowNumber,
                    'Lagnam',
                    (string) $currentLagnam,
                    $normalizedLagnam,
                    'Unknown or unmapped Lagnam value.'
                );
            }

            $position = (int) $positionCell;

            if ($position < 1 || $position > 12) {
                $this->addRule002Issue(
                    $workbookResult,
                    $issues,
                    $worksheet,
                    $rowNumber,
                    'Position',
                    $positionCell,
                    (string) $position,
                    'Position must be an integer from 1 to 12.'
                );
            }

            if ($normalizedLagnam !== '') {
                if (isset($positionsByLagnam[$normalizedLagnam][$position])) {
                    $this->addRule002Issue(
                        $workbookResult,
                        $issues,
                        $worksheet,
                        $rowNumber,
                        'Combination',
                        "{$currentLagnam} / {$positionCell}",
                        "{$normalizedLagnam}|{$position}",
                        'Duplicate effective match row; first found at row '.$positionsByLagnam[$normalizedLagnam][$position]
                    );
                } else {
                    $positionsByLagnam[$normalizedLagnam][$position] = $rowNumber;
                }

            }

            foreach ($this->invalidRule002YearCells($row) as $column => $value) {
                $this->addRule002Issue(
                    $workbookResult,
                    $issues,
                    $worksheet,
                    $rowNumber,
                    'Year',
                    $value,
                    '',
                    "Invalid Rule002 year cell in column {$column}."
                );
            }

            $groups = $this->rule002Lookup->yearVideoGroups($row);

            if ($groups === []) {
                $this->addRule002Issue(
                    $workbookResult,
                    $issues,
                    $worksheet,
                    $rowNumber,
                    'Year',
                    '',
                    '',
                    'No Rule002 year cell found for this position row.'
                );
            }

            foreach ($groups as $group) {
                $yearValue = (string) $group['matched_year'];

                if (! $this->rule002Lookup->cellContainsAnyYear($yearValue)) {
                    $this->addRule002Issue(
                        $workbookResult,
                        $issues,
                        $worksheet,
                        $rowNumber,
                        'Year',
                        $yearValue,
                        '',
                        'Invalid Rule002 year cell.'
                    );
                }

                if (($group['video_columns'] ?? []) === []) {
                    $this->addRule002Issue(
                        $workbookResult,
                        $issues,
                        $worksheet,
                        $rowNumber,
                        'Video',
                        '',
                        '',
                        'Missing video cell after year '.$yearValue.'.'
                    );

                    continue;
                }

                if (($group['video_ids'] ?? []) === []) {
                    $this->addRule002Issue(
                        $workbookResult,
                        $issues,
                        $worksheet,
                        $rowNumber,
                        'Video',
                        '',
                        '',
                        'Missing Video ID for year '.$yearValue.'.'
                    );
                }
            }

            if ($rowIssuesBefore === count($issues)) {
                $workbookResult['valid_rows']++;
            } else {
                $workbookResult['issue_rows']++;
            }
        }

        foreach ($validLagnams as $normalizedLagnam => $label) {
            for ($position = 1; $position <= 12; $position++) {
                if (! isset($positionsByLagnam[$normalizedLagnam][$position])) {
                    $this->addRule002Issue(
                        $workbookResult,
                        $issues,
                        $worksheet,
                        0,
                        'Position',
                        '',
                        "{$normalizedLagnam}|{$position}",
                        "Missing position {$position} for Lagnam {$label}."
                    );
                }
            }
        }
    }

    private function validateRule002AgeRangeSet(array &$workbooks, array &$issues, array $rangeRecords, string $period): void
    {
        usort($rangeRecords, fn (array $a, array $b): int => [$a['from'], $a['to'], $a['file']] <=> [$b['from'], $b['to'], $b['file']]);
        $previous = null;

        foreach ($rangeRecords as $record) {
            if ($previous !== null && $record['from'] <= $previous['to']) {
                $index = $this->workbookIndex($workbooks, $record['file']);

                if ($index !== null) {
                    $this->addRule002Issue(
                        $workbooks[$index],
                        $issues,
                        null,
                        0,
                        'Age range',
                        "{$record['from']}-{$record['to']}",
                        "{$record['from']}-{$record['to']}",
                        "Overlapping age workbook range with {$previous['file']}."
                    );
                    $workbooks[$index]['overlapping_age_ranges']++;
                }
            }

            $previous = $record;
        }
    }

    private function addRule002Issue(
        array &$workbookResult,
        array &$issues,
        ?string $worksheet,
        int|string $row,
        string $field,
        string $excelValue,
        string $normalizedValue,
        string $issue
    ): void {
        $this->incrementRule002Counter($workbookResult, $issue, $field);

        $issues[] = [
            'workbook' => $workbookResult['workbook'],
            'period' => $workbookResult['period'],
            'age_range' => $workbookResult['age_range'],
            'worksheet' => $worksheet,
            'row' => (int) $row,
            'field' => $field,
            'excel_value' => $excelValue,
            'normalized_value' => $normalizedValue,
            'issue' => $issue,
        ];
    }

    private function incrementRule002Counter(array &$workbookResult, string $issue, string $field): void
    {
        if (str_contains($issue, 'Unknown or unmapped') || str_contains($issue, 'not mapped')) {
            $workbookResult['mapping_mismatches']++;
        }

        if (str_contains($issue, 'Missing position')) {
            $workbookResult['missing_positions']++;
        }

        if ($field === 'Year' || str_contains($issue, 'year cell')) {
            $workbookResult['invalid_year_cells']++;
        }

        if (str_contains($issue, 'Video ID') || str_contains($issue, 'video cell')) {
            $workbookResult['missing_video_ids']++;
        }

        if (str_contains($issue, 'Duplicate effective match row')) {
            $workbookResult['duplicate_combinations']++;
        }

        if (
            str_contains($issue, 'Structural row problem')
            || str_contains($issue, 'Required Dasha worksheet')
            || str_contains($issue, 'Workbook filename')
            || str_contains($issue, 'Workbook age range')
            || $field === 'Worksheet'
            || $field === 'Workbook'
        ) {
            $workbookResult['structural_row_problems']++;
        }
    }

    private function emptyRule002WorkbookResult(string $fileName, string $period, ?array $range): array
    {
        return [
            'workbook' => $fileName,
            'period' => $period,
            'age_range' => [
                'from' => $range[0] ?? null,
                'to' => $range[1] ?? null,
            ],
            'worksheets_checked' => 0,
            'total_rows' => 0,
            'valid_rows' => 0,
            'issue_rows' => 0,
            'mapping_mismatches' => 0,
            'missing_positions' => 0,
            'invalid_year_cells' => 0,
            'missing_video_ids' => 0,
            'duplicate_combinations' => 0,
            'structural_row_problems' => 0,
            'overlapping_age_ranges' => 0,
            'valid' => true,
        ];
    }

    private function rule002Summary(array &$workbooks): array
    {
        $summary = [
            'workbooks_checked' => count($workbooks),
            'worksheets_checked' => 0,
            'total_rows' => 0,
            'valid_rows' => 0,
            'issue_rows' => 0,
            'mapping_mismatches' => 0,
            'missing_positions' => 0,
            'invalid_year_cells' => 0,
            'missing_video_ids' => 0,
            'duplicate_combinations' => 0,
            'structural_row_problems' => 0,
            'overlapping_age_ranges' => 0,
        ];

        foreach ($workbooks as &$workbook) {
            $workbook['valid'] = $workbook['mapping_mismatches'] === 0
                && $workbook['missing_positions'] === 0
                && $workbook['invalid_year_cells'] === 0
                && $workbook['missing_video_ids'] === 0
                && $workbook['duplicate_combinations'] === 0
                && $workbook['structural_row_problems'] === 0
                && $workbook['overlapping_age_ranges'] === 0;

            foreach ($summary as $key => $value) {
                if ($key === 'workbooks_checked') {
                    continue;
                }

                $summary[$key] += (int) ($workbook[$key] ?? 0);
            }
        }

        unset($workbook);

        return $summary;
    }

    private function rule002ErrorResponse(string $period, string $message): array
    {
        return [
            'success' => false,
            'valid' => false,
            'validator' => 'rule002_past_workbooks',
            'period' => $period,
            'message' => $message,
            'summary' => null,
            'workbooks' => [],
            'issues' => [],
        ];
    }

    private function requiredRule002DashaSheets(): array
    {
        $required = [];

        foreach (config('prediction.name_mappings.dasha_sheets', []) as $sheetName) {
            $normalized = $this->nameMapper->comparisonValue($sheetName, 'dasha_sheets');

            if ($normalized !== '') {
                $required[$normalized] = $sheetName;
            }
        }

        return $required;
    }

    private function validWorkbookLagnamValues(): array
    {
        $valid = [];

        foreach (config('prediction.name_mappings.workbook_lagnams', []) as $value) {
            $normalized = $this->nameMapper->comparisonValue($value, 'workbook_lagnams');

            if ($normalized !== '') {
                $valid[$normalized] = $value;
            }
        }

        return $valid;
    }

    private function rowHasRule002Data(array $row): bool
    {
        foreach ($row as $column => $value) {
            if (in_array($column, ['A', 'B'], true)) {
                continue;
            }

            if (trim((string) $value) !== '') {
                return true;
            }
        }

        return false;
    }

    private function invalidRule002YearCells(array $row): array
    {
        $invalid = [];

        foreach ($row as $column => $value) {
            if (in_array($column, ['A', 'B'], true)) {
                continue;
            }

            $trimmed = trim((string) $value);

            if ($trimmed === '' || $this->rule002Lookup->cellContainsAnyYear($trimmed)) {
                continue;
            }

            if (preg_match('/\b(19|20|21)\d{2}\b/', $trimmed) === 1) {
                $invalid[$column] = $trimmed;
            }
        }

        return $invalid;
    }

    private function workbookIndex(array $workbooks, string $fileName): ?int
    {
        foreach ($workbooks as $index => $workbook) {
            if (($workbook['workbook'] ?? null) === $fileName) {
                return $index;
            }
        }

        return null;
    }

    private function canonicalValues(
        array $values,
        string $domain
    ): array {
        $result = [];

        foreach ($values as $value) {
            $normalized = $this->nameMapper->comparisonValue(
                $value,
                $domain
            );

            if ($normalized !== '') {
                $result[$normalized] = true;
            }
        }

        return $result;
    }

    private function headers(array $rows): array
    {
        $headers = [];

        foreach (($rows[1] ?? []) as $column => $header) {
            $normalized = $this->nameMapper->headerValue($header);

            if ($normalized !== '') {
                $headers[$normalized] = $column;
            }
        }

        return $headers;
    }

    private function issue(
        int|string $row,
        string $field,
        string $excelValue,
        string $normalizedValue,
        string $issue
    ): array {
        return [
            'row' => (int) $row,
            'field' => $field,
            'excel_value' => $excelValue,
            'normalized_value' => $normalizedValue,
            'issue' => $issue,
        ];
    }
}
