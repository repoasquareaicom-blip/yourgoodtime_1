<?php

namespace Tests\Feature;

use App\Models\PredictionRequest;
use App\Services\Prediction\DeveloperTrace;
use App\Services\Prediction\PredictionResponseBuilder;
use App\Services\Prediction\PredictionTimeline;
use App\Services\Prediction\RuleResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PredictionResponseGroupingTest extends TestCase
{
    use RefreshDatabase;

    public function test_excel_range_produces_one_grouped_result_for_two_matching_past_ages(): void
    {
        $response = $this->buildResponse([
            $this->yearPrediction(age: 52, year: 2026, excelSequenceYear: 2014, matchedYear: '2013-2014'),
            $this->yearPrediction(age: 51, year: 2025, excelSequenceYear: 2013, matchedYear: '2013-2014'),
        ], 'past');

        $this->assertCount(1, $response['prediction_videos']);
        $this->assertSame(52, $response['prediction_videos'][0]['age']);
        $this->assertSame(2026, $response['prediction_videos'][0]['system_year']);
        $this->assertSame(2014, $response['prediction_videos'][0]['excel_sequence_year']);
        $this->assertSame(51, $response['prediction_videos'][0]['age_from']);
        $this->assertSame(52, $response['prediction_videos'][0]['age_to']);
        $this->assertSame(52, $response['prediction_videos'][0]['age_start']);
        $this->assertSame(51, $response['prediction_videos'][0]['age_end']);
        $this->assertSame(2025, $response['prediction_videos'][0]['system_year_from']);
        $this->assertSame(2026, $response['prediction_videos'][0]['system_year_to']);
        $this->assertSame(2026, $response['prediction_videos'][0]['system_year_start']);
        $this->assertSame(2025, $response['prediction_videos'][0]['system_year_end']);
        $this->assertSame('52-51', $response['prediction_videos'][0]['age_label']);
        $this->assertSame('2025-26', $response['prediction_videos'][0]['system_year_label']);
        $this->assertSame(2013, $response['prediction_videos'][0]['excel_sequence_year_from']);
        $this->assertSame(2014, $response['prediction_videos'][0]['excel_sequence_year_to']);
        $this->assertSame(2014, $response['prediction_videos'][0]['excel_sequence_year_start']);
        $this->assertSame(2013, $response['prediction_videos'][0]['excel_sequence_year_end']);
        $this->assertSame(2039, $response['prediction_videos'][0]['dasha_end_year']);
        $this->assertSame('2019-39', $response['prediction_videos'][0]['dasha_year_label']);
        $this->assertSame(2, $response['summary']['total_years']);
        $this->assertSame(2, $response['summary']['successful_years']);
        $this->assertSame(1, $response['summary']['grouped_result_count']);
    }

    public function test_past_results_are_sorted_by_system_year_from_ascending(): void
    {
        $response = $this->buildResponse([
            $this->yearPrediction(age: 52, year: 2026, excelSequenceYear: 2014, matchedYear: '2013-2014', videoIds: ['6153']),
            $this->yearPrediction(age: 51, year: 2025, excelSequenceYear: 2013, matchedYear: '2013-2014', videoIds: ['6153']),
            $this->yearPrediction(age: 50, year: 2024, excelSequenceYear: 2012, matchedYear: '2011-2012', videoIds: ['6139']),
            $this->yearPrediction(age: 49, year: 2023, excelSequenceYear: 2011, matchedYear: '2011-2012', videoIds: ['6139']),
        ], 'past');

        $this->assertCount(2, $response['prediction_videos']);
        $this->assertSame('50-49', $response['prediction_videos'][0]['age_label']);
        $this->assertSame(2023, $response['prediction_videos'][0]['system_year_from']);
        $this->assertSame('52-51', $response['prediction_videos'][1]['age_label']);
        $this->assertSame(2025, $response['prediction_videos'][1]['system_year_from']);
    }

    public function test_single_year_excel_header_remains_a_single_age_result(): void
    {
        $response = $this->buildResponse([
            $this->yearPrediction(age: 37, year: 2024, excelSequenceYear: 2009, matchedYear: '2009', videoIds: ['6121']),
        ]);

        $this->assertCount(1, $response['prediction_videos']);
        $this->assertSame(37, $response['prediction_videos'][0]['age']);
        $this->assertSame(37, $response['prediction_videos'][0]['age_from']);
        $this->assertSame(37, $response['prediction_videos'][0]['age_to']);
        $this->assertSame(37, $response['prediction_videos'][0]['age_start']);
        $this->assertSame(37, $response['prediction_videos'][0]['age_end']);
        $this->assertSame('37', $response['prediction_videos'][0]['age_label']);
        $this->assertSame(2024, $response['prediction_videos'][0]['system_year']);
        $this->assertSame(2024, $response['prediction_videos'][0]['system_year_start']);
        $this->assertSame(2024, $response['prediction_videos'][0]['system_year_end']);
        $this->assertSame('2024', $response['prediction_videos'][0]['system_year_label']);
        $this->assertSame(2009, $response['prediction_videos'][0]['excel_sequence_year']);
        $this->assertSame(2009, $response['prediction_videos'][0]['excel_sequence_year_start']);
        $this->assertSame(2009, $response['prediction_videos'][0]['excel_sequence_year_end']);
    }

    public function test_same_video_ids_but_different_matched_ranges_are_not_merged(): void
    {
        $response = $this->buildResponse([
            $this->yearPrediction(age: 35, year: 2010, excelSequenceYear: 2007, matchedYear: '2007', videoIds: ['6120']),
            $this->yearPrediction(age: 36, year: 2011, excelSequenceYear: 2008, matchedYear: '2008', videoIds: ['6120']),
        ]);

        $this->assertCount(2, $response['prediction_videos']);
    }

    public function test_same_matched_range_but_different_position_is_not_merged(): void
    {
        $response = $this->buildResponse([
            $this->yearPrediction(age: 35, year: 2010, excelSequenceYear: 2007, matchedYear: '2007-2008', position: 1),
            $this->yearPrediction(age: 36, year: 2011, excelSequenceYear: 2008, matchedYear: '2007-2008', position: 2),
        ]);

        $this->assertCount(2, $response['prediction_videos']);
    }

    public function test_same_matched_range_but_different_rasi_is_not_merged(): void
    {
        $response = $this->buildResponse([
            $this->yearPrediction(age: 35, year: 2010, excelSequenceYear: 2007, matchedYear: '2007-2008', rasi: 'MEENAM'),
            $this->yearPrediction(age: 36, year: 2011, excelSequenceYear: 2008, matchedYear: '2007-2008', rasi: 'THULAM'),
        ]);

        $this->assertCount(2, $response['prediction_videos']);
    }

    public function test_same_matched_range_but_different_dasha_is_not_merged(): void
    {
        $response = $this->buildResponse([
            $this->yearPrediction(age: 35, year: 2010, excelSequenceYear: 2007, matchedYear: '2007-2008', majorDasha: 'Venus'),
            $this->yearPrediction(age: 36, year: 2011, excelSequenceYear: 2008, matchedYear: '2007-2008', majorDasha: 'Sun'),
        ]);

        $this->assertCount(2, $response['prediction_videos']);
    }

    public function test_warning_results_are_not_merged(): void
    {
        $response = $this->buildResponse([
            $this->yearPrediction(age: 35, year: 2010, excelSequenceYear: 2007, matchedYear: '2007-2008', status: 'UNAVAILABLE', videoIds: [], warning: 'Excel sequence year column not found.'),
            $this->yearPrediction(age: 36, year: 2011, excelSequenceYear: 2008, matchedYear: '2007-2008', status: 'UNAVAILABLE', videoIds: [], warning: 'Excel sequence year column not found.'),
        ]);

        $this->assertCount(2, $response['prediction_videos']);
        $this->assertSame(2, $response['summary']['total_years']);
        $this->assertSame(0, $response['summary']['successful_years']);
        $this->assertSame(2, $response['summary']['unavailable_years']);
    }

    public function test_future_ascending_input_produces_correct_range_labels(): void
    {
        $response = $this->buildResponse([
            $this->yearPrediction(age: 35, year: 2010, excelSequenceYear: 2007, matchedYear: '2007-2008'),
            $this->yearPrediction(age: 36, year: 2011, excelSequenceYear: 2008, matchedYear: '2007-2008'),
        ], 'future');

        $this->assertCount(1, $response['prediction_videos']);
        $this->assertSame('35-36', $response['prediction_videos'][0]['age_label']);
        $this->assertSame('2010-11', $response['prediction_videos'][0]['system_year_label']);
    }

    public function test_system_year_label_uses_four_digit_years_when_crossing_century(): void
    {
        $response = $this->buildResponse([
            $this->yearPrediction(age: 35, year: 1999, excelSequenceYear: 2007, matchedYear: '2007-2008'),
            $this->yearPrediction(age: 36, year: 2000, excelSequenceYear: 2008, matchedYear: '2007-2008'),
        ], 'future');

        $this->assertCount(1, $response['prediction_videos']);
        $this->assertSame('1999-2000', $response['prediction_videos'][0]['system_year_label']);
    }

    private function buildResponse(array $yearlyPredictions, string $predictionType = 'past'): array
    {
        $timeline = new PredictionTimeline(
            predictionRequest: $this->predictionRequest(),
            predictionType: $predictionType,
            effectiveYear: 2011,
            fromYear: 2010,
            toYear: 2011,
            configuredYears: count($yearlyPredictions),
            candidateYears: count($yearlyPredictions),
            processedYears: count($yearlyPredictions),
            excludedAgeCount: 0,
            excludedPurchasedCount: 0,
            excludedPurchasedYears: [],
            minimumSupportedAge: $predictionType === 'past' ? 16 : 22,
            maximumSupportedAge: 60,
            status: 'READY',
            yearSessions: [],
            introPlaylist: [],
            yearlyPredictions: $yearlyPredictions,
            ruleResults: [],
            developerTrace: new DeveloperTrace()
        );

        return app(PredictionResponseBuilder::class)->buildTimeline($timeline);
    }

    private function yearPrediction(
        int $age,
        int $year,
        int $excelSequenceYear,
        string $matchedYear,
        array $videoIds = ['6120'],
        string $status = 'SUCCESS',
        ?string $warning = null,
        string $majorDasha = 'Venus',
        int $dashaStartYear = 2019,
        int $dashaEndYear = 2039,
        string $workbook = '33 TO 37.xlsx',
        string $sheet = 'VENUS',
        string $lagnam = 'MESHAM',
        string $rasi = 'MEENAM',
        int $position = 1
    ): array {
        $ruleResult = new RuleResult(
            ruleCode: 'RULE-002',
            ruleName: 'Age Year Mahadasha Prediction',
            status: $status,
            excelFile: $workbook,
            worksheet: $sheet,
            matchedValues: [
                'lagnam' => $lagnam,
                'rasi' => $rasi,
                'position' => $position,
                'matched_year' => $matchedYear,
                'video_ids' => $videoIds,
            ],
            identifiedValues: [
                'mahadasha' => $majorDasha,
            ],
            dashaStartDate: '8-7-2019 18:21',
            dashaStartEffectiveYear: $dashaStartYear,
            dashaEndDate: '8-7-2039 18:21',
            dashaEndEffectiveYear: $dashaEndYear,
            excelSequenceYear: $excelSequenceYear,
            remarks: $warning
        );

        return [
            'year' => $year,
            'age' => $age,
            'status' => $status,
            'playlist_items' => [],
            'rule_results' => [$ruleResult],
        ];
    }

    private function predictionRequest(): PredictionRequest
    {
        return PredictionRequest::query()->create([
            'request_uuid' => (string) Str::uuid(),
            'prediction_type' => 'past',
            'status' => 'completed',
            'full_name' => 'Ravi Kumar',
            'date_of_birth' => '1975-01-01',
            'time_of_birth' => '06:45',
            'timezone' => 'Asia/Kolkata',
            'place_of_birth' => 'Chennai, Tamil Nadu',
            'latitude' => 13.0827,
            'longitude' => 80.2707,
            'birth_payload' => [
                'day' => 1,
                'month' => 1,
                'year' => 1975,
                'hour' => 6,
                'min' => 45,
                'lat' => 13.0827,
                'lon' => 80.2707,
                'tzone' => 5.5,
            ],
            'raw_astrology_response' => [],
            'normalized_astrology_data' => [],
            'submitted_at' => now(),
            'completed_at' => now(),
        ]);
    }
}
