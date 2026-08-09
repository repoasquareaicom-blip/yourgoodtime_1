<?php

namespace App\Services\Prediction;

class PredictionResponseBuilder
{
    public function __construct(private readonly PredictionConfig $predictionConfig)
    {
    }

    public function build(PredictionSession $session, bool $debugRequested = false): array
    {
        $response = [
            'success' => true,
            'engine_version' => $this->predictionConfig->engineVersion(),
            'prediction_window' => $this->predictionWindow($session),
            'playlist_items' => $this->playlistItems($session->getPlaylistBuilder()),
        ];

        if (! $this->shouldIncludeDebug($debugRequested)) {
            return $response;
        }

        return array_merge($response, [
            'prediction_context' => $session->getPredictionContext()->toArray(),
            'rules' => array_map(
                fn (RuleResult $ruleResult): array => $ruleResult->toArray(),
                $session->getRuleResults()
            ),
            'developer_trace' => $this->predictionConfig->developerTraceEnabled()
                ? $session->getDeveloperTrace()->toArray()
                : null,
        ]);
    }

    public function buildTimeline(PredictionTimeline $timeline, bool $debugRequested = false): array
    {
        $yearlyPredictions = array_map(
            fn (array $prediction): array => $this->publicYearPrediction($prediction),
            $timeline->yearlyPredictions
        );
        $predictionVideos = $this->sortPredictionVideoGroups(
            $this->groupPredictionVideos($this->predictionVideos($timeline->yearlyPredictions))
        );

        $response = [
            'success' => true,
            'engine_version' => $this->predictionConfig->engineVersion(),
            'prediction_request_id' => $timeline->predictionRequest->id,
            'prediction_type' => $timeline->predictionType,
            'prediction_window' => [
                'from' => $timeline->fromYear,
                'to' => $timeline->toYear,
            ],
            'identity_video' => $this->identityVideo($timeline->introPlaylist, $timeline->ruleResults),
            'prediction_videos' => $predictionVideos,
            'intro_playlist' => $this->safeIntroPlaylist($timeline->introPlaylist),
            'yearly_predictions' => $yearlyPredictions,
            'summary' => $this->summary($yearlyPredictions, $predictionVideos, $timeline),
        ];

        if (! $this->shouldIncludeDebug($debugRequested)) {
            return $response;
        }

        return array_merge($response, [
            'rule_results' => array_map(
                fn (RuleResult $ruleResult): array => $ruleResult->toArray(),
                $timeline->ruleResults
            ),
            'developer_trace' => $this->predictionConfig->developerTraceEnabled()
                ? $timeline->developerTrace->toArray()
                : null,
        ]);
    }

    private function shouldIncludeDebug(bool $debugRequested): bool
    {
        return $debugRequested && $this->predictionConfig->debugResponseEnabled();
    }

    private function predictionWindow(PredictionSession $session): array
    {
        return [
            'past' => [
                'from' => $session->getPastStartYear(),
                'to' => $session->getPastEndYear(),
            ],
            'future' => [
                'from' => $session->getFutureStartYear(),
                'to' => $session->getFutureEndYear(),
            ],
        ];
    }

    private function playlistItems(PlaylistBuilder $playlistBuilder): array
    {
        return array_map(
            fn (mixed $playlistItem): mixed => $playlistItem,
            $playlistBuilder->getPlaylist()
        );
    }

    private function safeIntroPlaylist(array $playlist): array
    {
        return array_map(function (array $item): array {
            return [
                'sequence' => $item['sequence'] ?? null,
                'video_id' => $item['video_id'] ?? null,
                'source' => [
                    'rule_code' => $item['source']['rule_code'] ?? null,
                    'rule_name' => $item['source']['rule_name'] ?? null,
                ],
            ];
        }, $playlist);
    }

    private function identityVideo(array $playlist, array $ruleResults = []): ?array
    {
        $item = $playlist[0] ?? null;

        if (! is_array($item)) {
            return null;
        }

        $source = $item['source'] ?? [];
        $matchedValues = $source['matched_values'] ?? [];
        $ruleData = $this->firstRuleData($ruleResults, 'RULE-001');

        return [
            'video_id' => $item['video_id'] ?? null,
            'source' => [
                'file' => $ruleData['excel_file'] ?? config('prediction.files.nakshatra_lagnam', 'nakshatra_lagnam.xlsx'),
                'sheet' => $ruleData['worksheet'] ?? $matchedValues['sheet'] ?? null,
                'lagnam' => $matchedValues['lagnam'] ?? null,
                'rasi' => $matchedValues['rasi'] ?? null,
                'nakshatra' => $matchedValues['nakshatra'] ?? null,
                'match_position' => $matchedValues['position'] ?? null,
                'excel_row_number' => $ruleData['matched_row'] ?? $matchedValues['excel_row_number'] ?? null,
                'excel_column' => $ruleData['matched_column'] ?? null,
                'excel_cell' => $ruleData['matched_cell'] ?? null,
                'video' => $item['video_id'] ?? null,
                'lookup_values' => $source['identified_values'] ?? [],
                'status' => $ruleData['status'] ?? null,
                'warning' => ($ruleData['status'] ?? null) === 'SUCCESS' ? null : ($ruleData['remarks'] ?? null),
            ],
        ];
    }

    private function publicYearPrediction(array $prediction): array
    {
        $response = [
            'year' => $prediction['year'],
            'age' => $prediction['age'],
            'status' => $prediction['status'],
            'playlist_items' => $this->safeYearPlaylist($prediction['playlist_items']),
        ];

        if ($prediction['status'] === 'UNAVAILABLE') {
            $response['display_message'] = 'Prediction is currently unavailable for this year.';
        }

        return $response;
    }

    private function safeYearPlaylist(array $playlist): array
    {
        return array_map(function (array $item): array {
            $source = $item['source'] ?? [];
            $matchedValues = $source['matched_values'] ?? [];

            unset($matchedValues['workbook'], $matchedValues['worksheet']);

            return [
                'sequence' => $item['sequence'] ?? null,
                'video_id' => $item['video_id'] ?? null,
                'source' => [
                    'rule_code' => $source['rule_code'] ?? null,
                    'rule_name' => $source['rule_name'] ?? null,
                    'identified_values' => $source['identified_values'] ?? [],
                    'matched_values' => $matchedValues,
                ],
            ];
        }, $playlist);
    }

    private function predictionVideos(array $yearlyPredictions): array
    {
        $results = [];

        foreach ($yearlyPredictions as $prediction) {
            $ruleResult = $prediction['rule_results'][0] ?? null;
            $ruleData = $ruleResult instanceof RuleResult ? $ruleResult->toArray() : [];
            $matchedValues = $ruleData['matched_values'] ?? [];
            $identifiedValues = $ruleData['identified_values'] ?? [];
            $videoIds = $matchedValues['video_ids'] ?? [];

            if (! is_array($videoIds)) {
                $videoIds = $videoIds === null || $videoIds === '' ? [] : [$videoIds];
            }

            $results[] = [
                'age' => $prediction['age'] ?? null,
                'system_year' => $prediction['year'] ?? null,
                'major_dasha' => $identifiedValues['mahadasha'] ?? null,
                'dasha_start_year' => $ruleData['dasha_start_effective_year'] ?? null,
                'dasha_end_year' => $ruleData['dasha_end_effective_year'] ?? null,
                'dasha_year_label' => $this->compactYearRangeLabel(
                    $ruleData['dasha_start_effective_year'] ?? null,
                    $ruleData['dasha_end_effective_year'] ?? null
                ),
                'dasha_start_date' => $ruleData['dasha_start_date'] ?? null,
                'dasha_end_date' => $ruleData['dasha_end_date'] ?? null,
                'excel_sequence_year' => $ruleData['excel_sequence_year'] ?? null,
                'workbook' => $ruleData['excel_file'] ?? null,
                'sheet' => $ruleData['worksheet'] ?? null,
                'lagnam' => $matchedValues['lagnam'] ?? null,
                'rasi' => $matchedValues['rasi'] ?? null,
                'position' => $matchedValues['position'] ?? null,
                'excel_row_number' => $ruleData['matched_row'] ?? null,
                'excel_year_column' => $ruleData['year_column'] ?? null,
                'excel_video_column' => $ruleData['video_column'] ?? null,
                'excel_cell' => $ruleData['matched_cell'] ?? null,
                'matched_year' => $matchedValues['matched_year'] ?? null,
                'video_ids' => $videoIds,
                'status' => $prediction['status'] ?? null,
                'warning' => ($prediction['status'] ?? null) === 'SUCCESS' ? null : ($ruleData['remarks'] ?? 'Prediction lookup did not find a match.'),
            ];
        }

        return $results;
    }

    private function firstRuleData(array $ruleResults, string $ruleCode): array
    {
        foreach ($ruleResults as $ruleResult) {
            $data = $ruleResult instanceof RuleResult ? $ruleResult->toArray() : (is_array($ruleResult) ? $ruleResult : []);

            $normalizedStoredCode = str_replace('-', '', (string) ($data['rule_code'] ?? ''));
            $normalizedRuleCode = str_replace('-', '', $ruleCode);

            if ($normalizedStoredCode === $normalizedRuleCode) {
                return $data;
            }
        }

        return [];
    }

    private function groupPredictionVideos(array $predictionVideos): array
    {
        $groups = [];

        foreach ($predictionVideos as $video) {
            $lastIndex = array_key_last($groups);

            if ($lastIndex !== null && $this->canMergePredictionVideo($groups[$lastIndex], $video)) {
                $groups[$lastIndex]['items'][] = $video;

                continue;
            }

            $groups[] = ['items' => [$video]];
        }

        return array_map(
            fn (array $group): array => $this->predictionVideoGroup($group['items']),
            $groups
        );
    }

    private function sortPredictionVideoGroups(array $groups): array
    {
        usort(
            $groups,
            fn (array $first, array $second): int => ((int) ($first['system_year_from'] ?? $first['system_year'] ?? 0))
                <=> ((int) ($second['system_year_from'] ?? $second['system_year'] ?? 0))
        );

        return $groups;
    }

    private function canMergePredictionVideo(array $group, array $video): bool
    {
        $items = $group['items'] ?? [];
        $previous = end($items);

        if (! is_array($previous)) {
            return false;
        }

        if (($previous['status'] ?? null) !== 'SUCCESS' || ($video['status'] ?? null) !== 'SUCCESS') {
            return false;
        }

        if (! empty($previous['warning']) || ! empty($video['warning'])) {
            return false;
        }

        if (($previous['video_ids'] ?? []) === [] || ($video['video_ids'] ?? []) === []) {
            return false;
        }

        if (abs((int) ($previous['age'] ?? 0) - (int) ($video['age'] ?? 0)) !== 1) {
            return false;
        }

        if (abs((int) ($previous['system_year'] ?? 0) - (int) ($video['system_year'] ?? 0)) !== 1) {
            return false;
        }

        if (abs((int) ($previous['excel_sequence_year'] ?? 0) - (int) ($video['excel_sequence_year'] ?? 0)) !== 1) {
            return false;
        }

        foreach ([
            'workbook',
            'major_dasha',
            'dasha_start_year',
            'sheet',
            'lagnam',
            'rasi',
            'position',
            'matched_year',
        ] as $field) {
            if (($previous[$field] ?? null) !== ($video[$field] ?? null)) {
                return false;
            }
        }

        return $this->effectiveVideoIds($previous['video_ids'] ?? []) === $this->effectiveVideoIds($video['video_ids'] ?? []);
    }

    private function predictionVideoGroup(array $items): array
    {
        $first = $items[0];
        $last = $items[array_key_last($items)];
        $ages = array_map(fn (array $item): int => (int) $item['age'], $items);
        $systemYears = array_map(fn (array $item): int => (int) $item['system_year'], $items);
        $excelSequenceYears = array_map(fn (array $item): int => (int) $item['excel_sequence_year'], $items);
        $ageFrom = min($ages);
        $ageTo = max($ages);
        $systemYearFrom = min($systemYears);
        $systemYearTo = max($systemYears);
        $excelSequenceYearFrom = min($excelSequenceYears);
        $excelSequenceYearTo = max($excelSequenceYears);
        $ageStart = (int) $first['age'];
        $ageEnd = (int) $last['age'];
        $systemYearStart = (int) $first['system_year'];
        $systemYearEnd = (int) $last['system_year'];
        $excelSequenceYearStart = (int) $first['excel_sequence_year'];
        $excelSequenceYearEnd = (int) $last['excel_sequence_year'];

        return array_merge($first, [
            'age' => $ageStart,
            'age_from' => $ageFrom,
            'age_to' => $ageTo,
            'age_start' => $ageStart,
            'age_end' => $ageEnd,
            'age_label' => $this->timelineRangeLabel($ageStart, $ageEnd),
            'system_year' => $systemYearStart,
            'system_year_from' => $systemYearFrom,
            'system_year_to' => $systemYearTo,
            'system_year_start' => $systemYearStart,
            'system_year_end' => $systemYearEnd,
            'system_year_label' => $this->compactYearRangeLabel($systemYearFrom, $systemYearTo),
            'excel_sequence_year' => $excelSequenceYearStart,
            'excel_sequence_year_from' => $excelSequenceYearFrom,
            'excel_sequence_year_to' => $excelSequenceYearTo,
            'excel_sequence_year_start' => $excelSequenceYearStart,
            'excel_sequence_year_end' => $excelSequenceYearEnd,
        ]);
    }

    private function effectiveVideoIds(array $videoIds): array
    {
        $normalized = array_values(array_unique(array_map('strval', $videoIds)));
        sort($normalized);

        return $normalized;
    }

    private function timelineRangeLabel(int $from, int $to): string
    {
        return $from === $to ? (string) $from : "{$from}-{$to}";
    }

    private function compactYearRangeLabel(null|int|string $startYear, null|int|string $endYear): ?string
    {
        if ($startYear === null || $endYear === null || $startYear === '' || $endYear === '') {
            return null;
        }

        $startYear = (int) $startYear;
        $endYear = (int) $endYear;

        if ($startYear === $endYear) {
            return (string) $startYear;
        }

        if (intdiv($startYear, 100) === intdiv($endYear, 100)) {
            return sprintf('%d-%02d', $startYear, $endYear % 100);
        }

        return "{$startYear}-{$endYear}";
    }

    private function summary(array $yearlyPredictions, array $predictionVideos, PredictionTimeline $timeline): array
    {
        return [
            'configured_years' => $timeline->configuredYears,
            'candidate_years' => $timeline->candidateYears,
            'processed_years' => $timeline->processedYears,
            'excluded_age_count' => $timeline->excludedAgeCount,
            'excluded_purchased_count' => $timeline->excludedPurchasedCount,
            'excluded_purchased_years' => $timeline->excludedPurchasedYears,
            'minimum_supported_age' => $timeline->minimumSupportedAge,
            'maximum_supported_age' => $timeline->maximumSupportedAge,
            'status' => $timeline->status,
            'from_year' => $timeline->fromYear,
            'to_year' => $timeline->toYear,
            'total_years' => count($yearlyPredictions),
            'successful_years' => count(array_filter($yearlyPredictions, fn (array $item): bool => $item['status'] === 'SUCCESS')),
            'partial_years' => count(array_filter($yearlyPredictions, fn (array $item): bool => $item['status'] === 'PARTIAL')),
            'unavailable_years' => count(array_filter($yearlyPredictions, fn (array $item): bool => $item['status'] === 'UNAVAILABLE')),
            'grouped_result_count' => count($predictionVideos),
        ];
    }
}
