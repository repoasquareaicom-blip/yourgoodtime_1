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
use App\Services\Prediction\PredictionSession;
use App\Services\Prediction\PredictionYearResolver;
use App\Services\Prediction\Rule002WorkbookLookupService;
use App\Services\Prediction\RuleResult;
use Illuminate\Support\Facades\Log;
use Throwable;

class Rule002CurrentMahadasha implements BusinessRuleInterface
{
    private const RULE_CODE = 'RULE-002';

    private const RULE_NAME = 'Age Year Mahadasha Prediction';

    public function __construct(
        private readonly AstrologyNameMapper $nameMapper,
        private readonly PredictionYearResolver $yearResolver,
        private readonly DashaSequenceYearResolver $sequenceYearResolver,
        private readonly AgeResolver $ageResolver,
        private readonly AgeWorkbookResolver $workbookResolver,
        private readonly Rule002WorkbookLookupService $workbookLookup
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

        $age = $session->getTargetAge() ?? $this->ageResolver->calculateAge($birthDate, $session->getPredictionDate());
        $dasha = $context->getMahadashaForSystemYear($systemYear);
        $this->logLookup('system year resolved', compact('age', 'systemYear', 'birthYear', 'predictionPeriod'));

        if ($dasha === null) {
            return $this->result($startedAt, 'NO_MATCH', $yearMetadata, $age, $predictionPeriod, null, null, [], [], 'Mahadasha data is unavailable for system year.');
        }

        try {
            $sequenceYear = $this->sequenceYearResolver->resolveForSystemYear($systemYear, $dasha->startDate, $dasha->endDate, $predictionPeriod);
            $this->logLookup('excel sequence year resolved', [
                'major_dasha' => $dasha->planet,
                'dasha_start_year' => $sequenceYear->dashaStartEffectiveYear,
                'system_year' => $systemYear,
                'prediction_type' => $predictionPeriod,
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
        $matchedValues = $this->matchedValues($workbook, null, $context->getLagnam(), $context->getRasi(), $planetHousePosition, $sequenceYear, $age);
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
            $lookup = $this->workbookLookup->lookupWorkbookMatch(
                predictionPeriod: $predictionPeriod,
                age: $age,
                workbook: $workbook,
                dasha: $dasha,
                lagnam: $context->getLagnam(),
                rasi: $context->getRasi(),
                position: $planetHousePosition,
                sequenceYear: $sequenceYear
            );
            $worksheet = $lookup['worksheet'];

            if ($lookup['status'] === 'worksheet_missing') {
                return $this->result($startedAt, 'NO_MATCH', $yearMetadata, $age, $predictionPeriod, $workbook, null, $identifiedValues, $matchedValues, 'No matching Mahadasha worksheet found.', sequenceYear: $sequenceYear);
            }

            $matchedValues = $this->matchedValues($workbook, $worksheet['worksheet'], $context->getLagnam(), $context->getRasi(), $planetHousePosition, $sequenceYear, $age);
            $rowMatches = $lookup['row_matches'];
            $this->logLookup('worksheet row lookup completed', [
                'sheet' => $worksheet['worksheet'],
                'lagnam' => $context->getLagnam(),
                'rasi' => $context->getRasi(),
                'position' => $planetHousePosition,
                'matches' => array_keys($rowMatches),
            ]);

            if ($lookup['status'] === 'row_ambiguous') {
                return $this->result($startedAt, 'ERROR', $yearMetadata, $age, $predictionPeriod, $workbook, $worksheet['worksheet'], $identifiedValues, $matchedValues, 'Ambiguous matching rows: '.implode(', ', array_keys($rowMatches)), sequenceYear: $sequenceYear);
            }

            if ($lookup['status'] === 'row_missing') {
                return $this->result($startedAt, 'NO_MATCH', $yearMetadata, $age, $predictionPeriod, $workbook, $worksheet['worksheet'], $identifiedValues, $matchedValues, 'No matching Lagnam, Rasi and position row found.', sequenceYear: $sequenceYear);
            }

            $matchedRow = $lookup['matched_row'];
            $rowMatch = $lookup['row_match'];
            $matchedValues['rasi'] = $rowMatch['matched_rasi'];
            $yearMatch = $lookup['year_match'];

            if ($lookup['status'] === 'year_missing') {
                return $this->result($startedAt, 'NO_MATCH', $yearMetadata, $age, $predictionPeriod, $workbook, $worksheet['worksheet'], $identifiedValues, $matchedValues, 'Excel sequence year column not found.', $matchedRow, sequenceYear: $sequenceYear);
            }

            if ($lookup['switched_workbook']) {
                $workbook = $lookup['workbook'];
                $worksheet = $lookup['worksheet'];
                $matchedRow = $lookup['matched_row'];
                $rowMatch = $lookup['row_match'];
                $yearMatch = $lookup['year_match'];
                $matchedValues = $this->matchedValues($workbook, $worksheet['worksheet'], $context->getLagnam(), $context->getRasi(), $planetHousePosition, $sequenceYear, $age);
                $matchedValues['rasi'] = $rowMatch['matched_rasi'];
                $this->logLookup('range video switched to next age workbook', [
                    'age' => $age,
                    'next_age' => $age + 1,
                    'workbook' => $workbook->fileName,
                    'sheet' => $worksheet['worksheet'],
                    'matched_year' => $yearMatch['matched_year'],
                    'video_ids' => $yearMatch['video_ids'],
                ]);
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
            'prediction_type' => $predictionPeriod,
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

    private function matchedValues(AgeWorkbookResult $workbook, ?string $worksheet, ?string $lagnam, ?string $rasi, ?int $position, DashaSequenceYearResult $sequenceYear, int $age): array
    {
        return [
            'workbook' => $workbook->fileName,
            'worksheet' => $worksheet,
            'lagnam' => $this->nameMapper->excelValue($lagnam, 'workbook_lagnams'),
            'rasi' => $this->nameMapper->excelValue($rasi, 'workbook_lagnams'),
            'position' => $position,
            'sequence_base_year' => $sequenceYear->sequenceBaseYear,
            'target_system_year' => $sequenceYear->predictionEffectiveYear,
            'target_age' => $age,
            'dasha_start_year' => $sequenceYear->dashaStartEffectiveYear,
            'calculated_excel_sequence_year' => $sequenceYear->excelSequenceYear,
            'sequence_offset' => $sequenceYear->sequenceOffset,
            'dasha_year_number' => $sequenceYear->dashaYearNumber,
            'excel_sequence_year' => $sequenceYear->excelSequenceYear,
            'supported_age_from' => $workbook->supportedFromAge,
            'supported_age_to' => $workbook->supportedToAge,
        ];
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
