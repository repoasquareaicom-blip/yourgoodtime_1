<?php

namespace Tests\Feature;

use App\Models\PredictionRequest;
use App\Models\PredictionRuleLog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class PredictionTimelinePrepareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        File::deleteDirectory($this->predictionDataDirectory());
    }

    public function test_past_timeline_generates_descending_years_runs_intro_once_and_rule_002_per_year(): void
    {
        config(['prediction.past_years' => 2]);
        $this->fakeIntroWorkbook();
        $this->fakeAgeWorkbook('past', '18.xlsx', 'SUN', 'MESHAM', 5, 2009, 'PAST_2026');
        $this->fakeAgeWorkbook('past', '17.xlsx', 'SUN', 'MESHAM', 5, 2008, 'PAST_2025');

        $predictionRequest = $this->createPredictionRequest([
            'date_of_birth' => '2008-04-01',
            'completed_at' => CarbonImmutable::parse('2026-07-25 10:00:00'),
            'normalized_astrology_data' => $this->normalizedData('Aries', 'Sun', 5),
        ]);

        $this->postJson('/api/predictions/past/prepare', [
            'prediction_request_id' => $predictionRequest->id,
            'prediction_type' => 'past',
        ])
            ->assertOk()
            ->assertJson([
                'success' => true,
                'prediction_request_id' => $predictionRequest->id,
                'prediction_type' => 'past',
                'prediction_window' => [
                    'from' => 2025,
                    'to' => 2026,
                ],
                'intro_playlist' => [
                    [
                        'sequence' => 1,
                        'video_id' => 'INTRO_001',
                        'source' => [
                            'rule_code' => 'RULE-001',
                        ],
                    ],
                ],
                'yearly_predictions' => [
                    [
                        'year' => 2026,
                        'age' => 18,
                        'status' => 'SUCCESS',
                        'playlist_items' => [
                            [
                                'video_id' => 'PAST_2026',
                            ],
                        ],
                    ],
                    [
                        'year' => 2025,
                        'age' => 17,
                        'status' => 'SUCCESS',
                        'playlist_items' => [
                            [
                                'video_id' => 'PAST_2025',
                            ],
                        ],
                    ],
                ],
                'summary' => [
                    'total_years' => 2,
                    'successful_years' => 2,
                    'partial_years' => 0,
                    'unavailable_years' => 0,
                ],
            ])
            ->assertJsonMissingPath('yearly_predictions.0.playlist_items.0.source.matched_values.year')
            ->assertJsonMissingPath('yearly_predictions.0.playlist_items.0.source.matched_values.workbook')
            ->assertJsonMissingPath('yearly_predictions.0.playlist_items.0.source.matched_values.worksheet')
            ->assertJsonMissingPath('yearly_predictions.0.playlist_items.0.source.matched_cell')
            ->assertJsonMissingPath('rule_results');

        $this->assertDatabaseCount('prediction_rule_logs', 0);
    }

    public function test_future_timeline_generates_ascending_years_and_debug_keeps_diagnostics(): void
    {
        config([
            'prediction.future_years' => 2,
            'prediction.age_limits.future.minimum' => 18,
            'prediction.debug_response' => true,
        ]);
        $this->fakeIntroWorkbook();
        $this->fakeAgeWorkbook('future', 'FUTURE_18 TO 19.xlsx', 'SUN', 'MESHAM', 5, 2001, 'FUTURE_2027');

        $predictionRequest = $this->createPredictionRequest([
            'prediction_type' => 'future',
            'date_of_birth' => '2009-04-01',
            'completed_at' => CarbonImmutable::parse('2026-07-25 10:00:00'),
            'normalized_astrology_data' => $this->normalizedData('Aries', 'Sun', 5),
        ]);

        $this->postJson('/api/predictions/past/prepare?debug=true', [
            'prediction_request_id' => $predictionRequest->id,
            'prediction_type' => 'future',
        ])
            ->assertOk()
            ->assertJsonPath('prediction_window.from', 2027)
            ->assertJsonPath('prediction_window.to', 2028)
            ->assertJsonPath('yearly_predictions.0.year', 2027)
            ->assertJsonPath('yearly_predictions.1.year', 2028)
            ->assertJsonPath('yearly_predictions.0.status', 'SUCCESS')
            ->assertJsonPath('yearly_predictions.1.status', 'UNAVAILABLE')
            ->assertJsonPath('summary.total_years', 2)
            ->assertJsonPath('summary.successful_years', 1)
            ->assertJsonPath('summary.unavailable_years', 1)
            ->assertJsonPath('rule_results.0.rule_code', 'RULE-001')
            ->assertJsonPath('rule_results.1.rule_code', 'RULE-002')
            ->assertJsonPath('rule_results.2.rule_code', 'RULE-002')
            ->assertJsonStructure(['developer_trace']);
    }

    public function test_age_outside_workbook_range_logs_failure_and_does_not_stop_remaining_years(): void
    {
        config(['prediction.past_years' => 2]);
        $this->fakeIntroWorkbook();
        $this->fakeAgeWorkbook('past', '17.xlsx', 'SUN', 'MESHAM', 5, 2008, 'AVAILABLE_2025');

        $predictionRequest = $this->createPredictionRequest([
            'date_of_birth' => '2008-04-01',
            'completed_at' => CarbonImmutable::parse('2026-07-25 10:00:00'),
            'normalized_astrology_data' => $this->normalizedData('Aries', 'Sun', 5),
        ]);

        $this->postJson('/api/predictions/past/prepare', [
            'prediction_request_id' => $predictionRequest->id,
            'prediction_type' => 'past',
        ])
            ->assertOk()
            ->assertJsonPath('yearly_predictions.0.year', 2026)
            ->assertJsonPath('yearly_predictions.0.status', 'UNAVAILABLE')
            ->assertJsonPath('yearly_predictions.0.display_message', 'Prediction is currently unavailable for this year.')
            ->assertJsonPath('yearly_predictions.1.year', 2025)
            ->assertJsonPath('yearly_predictions.1.status', 'SUCCESS')
            ->assertJsonPath('summary.successful_years', 1)
            ->assertJsonPath('summary.unavailable_years', 1)
            ->assertJsonMissingPath('yearly_predictions.0.error_code')
            ->assertJsonMissingPath('yearly_predictions.0.message');

        $log = PredictionRuleLog::query()->where('target_year', 2026)->firstOrFail();

        $this->assertSame('NO_MATCH', $log->status);
        $this->assertSame('AGE_WORKBOOK_NOT_FOUND', $log->error_code);
        $this->assertSame(18, $log->age);
        $this->assertSame(17, $log->context_json['matched_values']['supported_age_from']);
        $this->assertSame(17, $log->context_json['matched_values']['supported_age_to']);
    }

    public function test_prediction_type_is_strictly_validated(): void
    {
        $predictionRequest = $this->createPredictionRequest();

        $this->postJson('/api/predictions/past/prepare', [
            'prediction_request_id' => $predictionRequest->id,
            'prediction_type' => 'all',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['prediction_type']);
    }

    private function createPredictionRequest(array $overrides = []): PredictionRequest
    {
        return PredictionRequest::query()->create(array_merge([
            'request_uuid' => (string) Str::uuid(),
            'prediction_type' => 'past',
            'status' => 'completed',
            'full_name' => 'Ravi Kumar',
            'date_of_birth' => '2008-04-01',
            'time_of_birth' => '06:45',
            'timezone' => 'Asia/Kolkata',
            'place_of_birth' => 'Chennai, Tamil Nadu',
            'latitude' => 13.0827,
            'longitude' => 80.2707,
            'birth_payload' => ['day' => 1, 'month' => 4, 'year' => 2008],
            'raw_astrology_response' => ['astro_details' => ['ascendant' => 'Mesha']],
            'normalized_astrology_data' => $this->normalizedData('Aries', 'Sun', 5),
            'submitted_at' => now(),
            'completed_at' => now(),
        ], $overrides));
    }

    private function normalizedData(string $lagnam, string $mahadasha, int $house): array
    {
        return [
            'birth' => [
                'date_of_birth' => '2008-04-01',
                'time_of_birth' => '06:45',
                'place_of_birth' => 'Chennai, Tamil Nadu',
                'latitude' => 13.0827,
                'longitude' => 80.2707,
            ],
            'identity' => [
                'lagnam' => $lagnam,
                'rasi' => 'Libra',
                'nakshatra' => 'Swati',
            ],
            'dasha' => [
                'current_major_lord' => $mahadasha,
                'current' => [
                    'major' => [
                        'planet' => $mahadasha,
                        'planet_id' => 0,
                        'start' => '1-4-2024  00:00',
                        'end' => '1-4-2030  00:00',
                    ],
                ],
                'major' => [
                    [
                        'planet' => $mahadasha,
                        'planet_id' => 0,
                        'start' => '1-4-2024  00:00',
                        'end' => '1-4-2030  00:00',
                    ],
                ],
            ],
            'planets' => [
                ['id' => 0, 'name' => $mahadasha, 'house' => $house],
            ],
        ];
    }

    private function fakeIntroWorkbook(): void
    {
        $directory = $this->predictionDataDirectory();

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        config(['prediction.excel_path' => $directory]);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Rule001');
        $sheet->fromArray([
            ['Nakshatra', 'Rasi', 'Lagnam', 'VideoId'],
            ['Swati', 'Libra', 'Aries', 'INTRO_001'],
        ]);

        (new Xlsx($spreadsheet))->save($directory.DIRECTORY_SEPARATOR.'nakshatra_lagnam.xlsx');
    }

    private function fakeAgeWorkbook(string $period, string $fileName, string $sheetName, string $lagnam, int $position, int|string $yearHeader, string $videoId): void
    {
        $directory = $this->predictionDataDirectory().DIRECTORY_SEPARATOR.$period;

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        config(['prediction.excel_path' => $this->predictionDataDirectory()]);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($sheetName);
        $sheet->fromArray([
            [$sheetName.' DASA', 'POSITION'],
            ['LAGNAM', ''],
            [$lagnam, (string) $position, $yearHeader, $videoId],
        ]);

        (new Xlsx($spreadsheet))->save($directory.DIRECTORY_SEPARATOR.$fileName);
    }

    private function predictionDataDirectory(): string
    {
        return storage_path('framework/testing/prediction-timeline-data');
    }
}
