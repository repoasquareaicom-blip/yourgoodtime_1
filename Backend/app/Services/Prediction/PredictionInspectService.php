<?php

namespace App\Services\Prediction;

use App\Models\PredictionEntitlement;
use App\Models\PredictionRequest;
use App\Models\PredictionResult;

class PredictionInspectService
{
    public function __construct(
        private readonly PredictionEngineService $predictionEngine,
        private readonly AstrologyNameMapper $nameMapper
    ) {
    }

    public function inspect(
        PredictionRequest $predictionRequest,
        PredictionResult $result,
        ?PredictionEntitlement $entitlement = null
    ): array {
        $predictionType = $result->prediction_type ?: $predictionRequest->prediction_type ?: 'past';
        $replay = $this->predictionEngine->replayForInspect(
            $predictionRequest,
            $predictionType,
            $this->yearTargets($result, $entitlement)
        );

        $identity = $this->identityDiagnostic($predictionRequest, $result, $replay);
        $years = $this->yearDiagnostics($result, $replay['years'] ?? []);
        $issues = array_values(array_filter([
            $identity['issue'] ?? null,
            ...array_column($years, 'issue'),
        ]));

        return [
            'mutates_result' => false,
            'summary' => $issues === []
                ? 'Current Excel lookup matches the stored result.'
                : count($issues).' issue'.(count($issues) === 1 ? '' : 's').' found against the current Excel files.',
            'issues' => $issues,
            'identity' => $identity,
            'years' => $years,
        ];
    }

    private function identityDiagnostic(
        PredictionRequest $predictionRequest,
        PredictionResult $result,
        array $replay
    ): array {
        $ruleData = $replay['rule001'] instanceof RuleResult
            ? $replay['rule001']->toArray()
            : (is_array($replay['rule001'] ?? null) ? $replay['rule001'] : []);
        $lookup = is_array($ruleData['lookup_values'] ?? null) ? $ruleData['lookup_values'] : $this->storedIdentity($predictionRequest);
        $mapped = is_array($ruleData['matched_values'] ?? null) ? $ruleData['matched_values'] : $this->mappedIdentity($lookup);
        $liveVideoId = trim((string) data_get($replay, 'intro_playlist.0.video_id', ''));
        $storedVideoId = trim((string) (
            data_get($result->result_payload, 'identity_video.video_id')
            ?: $result->identity_video_id
            ?: ''
        ));
        $status = $ruleData['status'] ?? ($liveVideoId === '' ? 'FAILED' : 'SUCCESS');
        $remarks = $ruleData['remarks'] ?? ($liveVideoId === '' ? 'No matching row found.' : 'Matching row found.');

        return [
            'status' => $status,
            'stored_video_id' => $storedVideoId === '' ? null : $storedVideoId,
            'current_video_id' => $liveVideoId === '' ? null : $liveVideoId,
            'lookup' => [
                'lagnam' => $lookup['lagnam'] ?? null,
                'rasi' => $lookup['rasi'] ?? null,
                'nakshatra' => $lookup['nakshatra'] ?? null,
            ],
            'mapped' => [
                'lagnam' => $mapped['lagnam'] ?? $this->nameMapper->excelValue($lookup['lagnam'] ?? null, 'workbook_lagnams'),
                'rasi' => $mapped['rasi'] ?? $this->nameMapper->excelValue($lookup['rasi'] ?? null, 'workbook_lagnams'),
                'nakshatra' => $mapped['nakshatra'] ?? $this->nameMapper->excelValue($lookup['nakshatra'] ?? null, 'nakshatras'),
            ],
            'excel_file' => $ruleData['excel_file'] ?? config('prediction.files.nakshatra_lagnam', 'nakshatra_lagnam.xlsx'),
            'sheet' => $ruleData['worksheet'] ?? null,
            'excel_row_number' => $ruleData['matched_row'] ?? null,
            'excel_cell' => $ruleData['matched_cell'] ?? null,
            'remarks' => $remarks,
            'issue' => $this->identityIssue($storedVideoId, $liveVideoId, $lookup, $mapped, $remarks, $ruleData),
        ];
    }

    private function yearDiagnostics(PredictionResult $result, array $replayYears): array
    {
        $liveYears = $this->liveYears($replayYears);
        $diagnostics = [];

        foreach ((array) data_get($result->result_payload, 'prediction_videos', []) as $stored) {
            if (! is_array($stored)) {
                continue;
            }

            $storedIds = $this->videoIds($stored);
            $live = $this->matchingLiveYear($liveYears, $stored);
            $liveIds = is_array($live) ? ($live['video_ids'] ?? []) : [];
            $hasStoredVideo = $storedIds !== [];
            $hasLiveVideo = $liveIds !== [];
            $idsDiffer = $hasStoredVideo && $hasLiveVideo && $storedIds !== $liveIds;

            if ($hasStoredVideo && $hasLiveVideo && ! $idsDiffer) {
                continue;
            }

            if ($hasStoredVideo && $live === null) {
                continue;
            }

            $age = $stored['age'] ?? data_get($live, 'age');
            $year = $stored['system_year'] ?? data_get($live, 'year');
            $lookup = is_array(data_get($live, 'lookup')) ? $live['lookup'] : [];
            $mapped = is_array(data_get($live, 'mapped')) ? $live['mapped'] : [];
            $remarks = data_get($live, 'remarks') ?: ($stored['warning'] ?? 'Prediction lookup did not find a match.');

            $diagnostics[] = [
                'age' => $age,
                'system_year' => $year,
                'stored_video_ids' => $storedIds,
                'current_video_ids' => $liveIds,
                'lookup' => $lookup,
                'mapped' => $mapped,
                'excel_file' => data_get($live, 'excel_file') ?? ($stored['workbook'] ?? $stored['excel_file'] ?? null),
                'sheet' => data_get($live, 'sheet') ?? ($stored['sheet'] ?? $stored['worksheet'] ?? null),
                'position' => data_get($live, 'position') ?? ($stored['position'] ?? null),
                'excel_row_number' => data_get($live, 'excel_row_number') ?? ($stored['excel_row_number'] ?? $stored['matched_row'] ?? null),
                'excel_year' => data_get($live, 'excel_year') ?? ($stored['excel_sequence_year'] ?? $stored['matched_year'] ?? null),
                'excel_cell' => data_get($live, 'excel_cell') ?? ($stored['excel_cell'] ?? null),
                'remarks' => $remarks,
                'issue' => $this->yearIssue($age, $year, $storedIds, $liveIds, $lookup, $mapped, $remarks, $live),
            ];
        }

        return $diagnostics;
    }

    private function liveYears(array $replayYears): array
    {
        $years = [];

        foreach ($replayYears as $prediction) {
            $ruleResult = $prediction['rule_results'][0] ?? null;
            $ruleData = $ruleResult instanceof RuleResult ? $ruleResult->toArray() : [];
            $identified = is_array($ruleData['identified_values'] ?? null) ? $ruleData['identified_values'] : [];
            $matched = is_array($ruleData['matched_values'] ?? null) ? $ruleData['matched_values'] : [];
            $playlistIds = [];

            foreach ((array) ($prediction['playlist_items'] ?? []) as $item) {
                $videoId = trim((string) ($item['video_id'] ?? ''));
                if ($videoId !== '') {
                    $playlistIds[] = $videoId;
                }
            }

            $matchedIds = $this->videoIds($matched);
            $videoIds = $playlistIds !== [] ? array_values(array_unique($playlistIds)) : $matchedIds;

            $years[] = [
                'age' => $prediction['age'] ?? $ruleData['age'] ?? null,
                'year' => $prediction['year'] ?? $ruleData['calendar_year'] ?? null,
                'status' => $prediction['status'] ?? $ruleData['status'] ?? null,
                'video_ids' => $videoIds,
                'lookup' => [
                    'lagnam' => $identified['lagnam'] ?? null,
                    'rasi' => $identified['rasi'] ?? null,
                    'mahadasha' => $identified['mahadasha'] ?? $identified['planet_name'] ?? null,
                    'position' => $identified['planet_house_position'] ?? $matched['position'] ?? null,
                ],
                'mapped' => [
                    'lagnam' => $matched['lagnam'] ?? $this->nameMapper->excelValue($identified['lagnam'] ?? null, 'workbook_lagnams'),
                    'rasi' => $matched['rasi'] ?? $this->nameMapper->excelValue($identified['rasi'] ?? null, 'workbook_lagnams'),
                    'position' => $matched['position'] ?? $identified['planet_house_position'] ?? null,
                ],
                'excel_file' => $ruleData['excel_file'] ?? $matched['workbook'] ?? null,
                'sheet' => $ruleData['worksheet'] ?? $matched['worksheet'] ?? null,
                'position' => $matched['position'] ?? $identified['planet_house_position'] ?? null,
                'excel_row_number' => $ruleData['matched_row'] ?? null,
                'excel_year' => $matched['matched_year'] ?? $ruleData['excel_sequence_year'] ?? null,
                'excel_cell' => $ruleData['matched_cell'] ?? null,
                'remarks' => $ruleData['remarks'] ?? null,
            ];
        }

        return $years;
    }

    private function matchingLiveYear(array $liveYears, array $stored): ?array
    {
        $age = $this->nullableInt($stored['age'] ?? null);
        $year = $this->nullableInt($stored['system_year'] ?? null);
        $ageFrom = $this->nullableInt($stored['age_from'] ?? $age);
        $ageTo = $this->nullableInt($stored['age_to'] ?? $age);
        $yearFrom = $this->nullableInt($stored['system_year_from'] ?? $year);
        $yearTo = $this->nullableInt($stored['system_year_to'] ?? $year);

        $exactAge = null;
        $rangeMatch = null;

        foreach ($liveYears as $live) {
            $liveAge = $this->nullableInt($live['age'] ?? null);
            $liveYear = $this->nullableInt($live['year'] ?? null);
            $ageMatch = $liveAge !== null && (
                $liveAge === $age
                || ($ageFrom !== null && $ageTo !== null && $liveAge >= $ageFrom && $liveAge <= $ageTo)
            );
            $yearMatch = $liveYear !== null && (
                $liveYear === $year
                || ($yearFrom !== null && $yearTo !== null && $liveYear >= $yearFrom && $liveYear <= $yearTo)
            );

            if ($liveAge === $age && $liveYear === $year) {
                return $live;
            }

            if ($exactAge === null && $liveAge === $age) {
                $exactAge = $live;
            }

            if ($rangeMatch === null && ($ageMatch || $yearMatch)) {
                $rangeMatch = $live;
            }
        }

        return $exactAge ?? $rangeMatch;
    }

    private function identityIssue(
        string $storedVideoId,
        string $liveVideoId,
        array $lookup,
        array $mapped,
        string $remarks,
        array $ruleData
    ): ?string {
        $lookupLabel = $this->identityLabel($lookup);
        $mappedLabel = $this->identityLabel($mapped);

        if ($liveVideoId === '' && $storedVideoId === '') {
            return 'Identity Video not found. Lookup '.$lookupLabel.' mapped to '.$mappedLabel.'. '.$remarks;
        }

        if ($liveVideoId === '' && $storedVideoId !== '') {
            return 'Stored Identity Video '.$storedVideoId.' exists, but current Excel lookup failed. Lookup '.$lookupLabel.' mapped to '.$mappedLabel.'. '.$remarks;
        }

        if ($storedVideoId === '' && $liveVideoId !== '') {
            $row = $ruleData['matched_row'] ?? null;

            return 'Stored Identity Video is missing. Current Excel lookup found video '.$liveVideoId.($row ? ' at row '.$row : '').'. Lookup '.$lookupLabel.' mapped to '.$mappedLabel.'.';
        }

        if ($storedVideoId !== $liveVideoId) {
            return 'Stored Identity Video '.$storedVideoId.' differs from current Excel video '.$liveVideoId.'. Lookup '.$lookupLabel.' mapped to '.$mappedLabel.'.';
        }

        return null;
    }

    private function yearIssue(
        mixed $age,
        mixed $year,
        array $storedIds,
        array $liveIds,
        array $lookup,
        array $mapped,
        string $remarks,
        ?array $live
    ): string {
        $label = 'Age '.($age ?? '-').' ('.($year ?? '-').')';
        $lookupLabel = trim(($lookup['lagnam'] ?? '-').' / '.($lookup['rasi'] ?? '-'));
        $mappedLabel = trim(($mapped['lagnam'] ?? '-').' / '.($mapped['rasi'] ?? '-'));
        $position = $live['position'] ?? $mapped['position'] ?? $lookup['position'] ?? null;
        $cell = $live['excel_cell'] ?? null;
        $file = $live['excel_file'] ?? null;
        $sheet = $live['sheet'] ?? null;
        $place = implode(' ', array_filter([
            $file,
            $sheet ? 'sheet '.$sheet : null,
            $position !== null && $position !== '' ? 'position '.$position : null,
            $cell ? 'cell '.$cell : null,
        ]));

        if ($storedIds === [] && $liveIds !== []) {
            return $label.' stored result has no video. Current Excel lookup found '.implode(', ', $liveIds).($place !== '' ? ' in '.$place : '').'. Lookup '.$lookupLabel.' mapped to '.$mappedLabel.'.';
        }

        if ($storedIds !== [] && $liveIds === []) {
            return $label.' stored video '.implode(', ', $storedIds).' was not found in the current Excel lookup. Lookup '.$lookupLabel.' mapped to '.$mappedLabel.'. '.$remarks;
        }

        if ($storedIds !== [] && $liveIds !== [] && $storedIds !== $liveIds) {
            return $label.' stored video '.implode(', ', $storedIds).' differs from current Excel video '.implode(', ', $liveIds).'.';
        }

        return $label.' has no video. Lookup '.$lookupLabel.' mapped to '.$mappedLabel.'. '.$remarks.($place !== '' ? ' '.$place.'.' : '');
    }

    private function identityLabel(array $values): string
    {
        return trim(($values['lagnam'] ?? '-').' / '.($values['rasi'] ?? '-').' / '.($values['nakshatra'] ?? '-'));
    }

    private function yearTargets(PredictionResult $result, ?PredictionEntitlement $entitlement = null): array
    {
        $targets = [];

        foreach ((array) data_get($result->result_payload, 'prediction_videos', []) as $stored) {
            if (! is_array($stored)) {
                continue;
            }

            $year = $this->nullableInt($stored['system_year'] ?? $stored['system_year_from'] ?? $stored['system_year_to'] ?? null);
            $age = $this->nullableInt($stored['age'] ?? $stored['age_from'] ?? $stored['age_to'] ?? null);

            if ($year === null || $age === null) {
                continue;
            }

            $targets[] = ['year' => $year, 'age' => $age];
        }

        if ($targets === [] && $entitlement?->access_start_year && $entitlement?->start_age) {
            $targets[] = [
                'year' => (int) $entitlement->access_start_year,
                'age' => (int) $entitlement->start_age,
            ];
        }

        return $targets;
    }

    private function storedIdentity(PredictionRequest $predictionRequest): array
    {
        $normalized = $predictionRequest->normalized_astrology_data;
        $identity = data_get($normalized, 'identity');

        if (! is_array($identity)) {
            $identity = data_get($normalized, 'value.identity', []);
        }

        return is_array($identity) ? $identity : [];
    }

    private function mappedIdentity(array $lookup): array
    {
        return [
            'lagnam' => $this->nameMapper->excelValue($lookup['lagnam'] ?? null, 'workbook_lagnams'),
            'rasi' => $this->nameMapper->excelValue($lookup['rasi'] ?? null, 'workbook_lagnams'),
            'nakshatra' => $this->nameMapper->excelValue($lookup['nakshatra'] ?? null, 'nakshatras'),
        ];
    }

    private function videoIds(array $video): array
    {
        $rawVideoIds = $video['video_ids'] ?? [];

        if (is_string($rawVideoIds) || is_numeric($rawVideoIds)) {
            $rawVideoIds = preg_split('/[\s,]+/', (string) $rawVideoIds) ?: [];
        }

        if (! is_array($rawVideoIds)) {
            $rawVideoIds = [];
        }

        if ($rawVideoIds === [] && ! empty($video['video_id'])) {
            $rawVideoIds = [$video['video_id']];
        }

        return array_values(array_unique(array_filter(
            array_map(fn ($videoId): string => trim((string) $videoId), $rawVideoIds),
            fn (string $videoId): bool => $videoId !== ''
        )));
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }
}
