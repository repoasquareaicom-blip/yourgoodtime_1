<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\PredictionRequest;
use App\Models\PredictionResult;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class AdminPredictionInspectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        File::deleteDirectory($this->predictionDataDirectory());

        config([
            'prediction.excel_path' => $this->predictionDataDirectory(),
        ]);
    }

    public function test_inspect_reports_identity_mapper_match_without_saving(): void
    {
        $this->fakeNakshatraLagnamWorkbookWithRows([
            ['LAGNAM', 'RASI', 'NAKSHATRA', 'VIDEO'],
            ['MESHAM', 'THULAM', 'Chitra', '19'],
            ['MESHAM', 'THULAM', 'Swathi    ', '20'],
        ]);

        $admin = $this->admin();
        $request = $this->predictionRequest([
            'normalized_astrology_data' => $this->normalizedData('Aries', 'Libra', 'Swati', 'Venus', 6),
        ]);
        $result = $this->predictionResult($request, [
            'identity_video' => null,
            'prediction_videos' => [
                ['age' => 52, 'system_year' => 2026, 'video_ids' => ['471']],
            ],
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/prediction-requests/{$request->id}/inspect?type=past&result_id={$result->id}")
            ->assertOk()
            ->assertJsonPath('data.mutates_result', false)
            ->assertJsonPath('data.identity.lookup.nakshatra', 'Swati')
            ->assertJsonPath('data.identity.mapped.nakshatra', 'Swathi')
            ->assertJsonPath('data.identity.current_video_id', '20')
            ->assertJsonPath('data.identity.stored_video_id', null)
            ->assertJsonPath('data.identity.excel_row_number', 3);

        $this->assertNull($result->refresh()->identity_video_id);
        $this->assertNull(data_get($result->result_payload, 'identity_video.video_id'));
    }

    public function test_inspect_reports_identity_row_miss_and_empty_year_current_video(): void
    {
        $this->fakeNakshatraLagnamWorkbookWithRows([
            ['LAGNAM', 'RASI', 'NAKSHATRA', 'VIDEO'],
            ['MESHAM', 'MEENAM', 'Swathi', 'INTRO_001'],
        ]);
        $this->createAgeWorkbook('past', '51 TO 60.xlsx', 'VENUS', $this->hierarchyRows('2014', ['PRED_2014']));

        $admin = $this->admin();
        $request = $this->predictionRequest([
            'date_of_birth' => '1973-12-16',
            'completed_at' => CarbonImmutable::parse('2026-07-25 10:00:00'),
            'submitted_at' => CarbonImmutable::parse('2026-07-25 09:00:00'),
            'normalized_astrology_data' => $this->normalizedData('Aries', 'Pisces', 'Swati', 'Venus', 6, birthDate: '1973-12-16'),
        ]);
        $result = $this->predictionResult($request, [
            'identity_video' => null,
            'prediction_videos' => [
                [
                    'age' => 52,
                    'system_year' => 2026,
                    'video_ids' => [],
                    'status' => 'UNAVAILABLE',
                    'warning' => 'Video value not found for Excel sequence year.',
                ],
            ],
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/prediction-requests/{$request->id}/inspect?type=past&result_id={$result->id}")
            ->assertOk()
            ->assertJsonPath('data.identity.current_video_id', 'INTRO_001')
            ->assertJsonPath('data.years.0.age', 52)
            ->assertJsonPath('data.years.0.stored_video_ids', [])
            ->assertJsonPath('data.years.0.current_video_ids.0', 'PRED_2014')
            ->assertJsonPath('data.years.0.mapped.lagnam', 'MESHAM')
            ->assertJsonPath('data.years.0.mapped.rasi', 'MEENAM');

        $this->assertNull($result->refresh()->identity_video_id);
        $this->assertSame([], data_get($result->refresh()->result_payload, 'prediction_videos.0.video_ids'));
    }

    public function test_inspect_reports_no_matching_identity_row(): void
    {
        $this->fakeNakshatraLagnamWorkbookWithRows([
            ['LAGNAM', 'RASI', 'NAKSHATRA', 'VIDEO'],
            ['MESHAM', 'THULAM', 'Swathi', '20'],
        ]);

        $admin = $this->admin();
        $request = $this->predictionRequest([
            'normalized_astrology_data' => $this->normalizedData('Aries', 'Libra', 'UnknownStar', 'Venus', 6),
        ]);
        $result = $this->predictionResult($request, [
            'identity_video' => null,
            'prediction_videos' => [
                ['age' => 40, 'system_year' => 2024, 'video_ids' => ['OK']],
            ],
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/prediction-requests/{$request->id}/inspect?type=past&result_id={$result->id}")
            ->assertOk()
            ->assertJsonPath('data.identity.current_video_id', null)
            ->assertJsonPath('data.identity.lookup.nakshatra', 'UnknownStar')
            ->assertJsonPath('data.identity.mapped.nakshatra', 'UnknownStar')
            ->assertJsonPath('data.identity.remarks', 'No matching row found.');

        $this->assertNotEmpty($this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/prediction-requests/{$request->id}/inspect?type=past&result_id={$result->id}")
            ->json('data.issues'));
    }

    public function test_inspect_requires_admin_authentication(): void
    {
        $request = $this->predictionRequest();

        $this->getJson("/api/admin/prediction-requests/{$request->id}/inspect?type=past")
            ->assertUnauthorized();
    }

    private function admin(): AdminUser
    {
        return AdminUser::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('secret123'),
            'is_active' => true,
        ]);
    }

    private function predictionRequest(array $overrides = []): PredictionRequest
    {
        return PredictionRequest::query()->create(array_merge([
            'request_uuid' => (string) Str::uuid(),
            'prediction_type' => 'past',
            'status' => 'completed',
            'full_name' => 'Ravi Kumar',
            'date_of_birth' => '1973-12-16',
            'time_of_birth' => '06:45',
            'timezone' => 'Asia/Kolkata',
            'place_of_birth' => 'Chennai, Tamil Nadu',
            'latitude' => 13.0827,
            'longitude' => 80.2707,
            'email' => 'ravi@example.com',
            'birth_payload' => [
                'day' => 16,
                'month' => 12,
                'year' => 1973,
                'hour' => 6,
                'min' => 45,
                'lat' => 13.0827,
                'lon' => 80.2707,
                'tzone' => 5.5,
            ],
            'raw_astrology_response' => ['provider' => 'stored'],
            'normalized_astrology_data' => $this->normalizedData('Aries', 'Pisces', 'Swati', 'Venus', 6),
            'submitted_at' => CarbonImmutable::parse('2026-07-25 09:00:00'),
            'completed_at' => CarbonImmutable::parse('2026-07-25 10:00:00'),
        ], $overrides));
    }

    private function predictionResult(PredictionRequest $request, array $payload): PredictionResult
    {
        return PredictionResult::query()->create([
            'prediction_request_id' => $request->id,
            'prediction_type' => 'past',
            'identity_video_id' => data_get($payload, 'identity_video.video_id'),
            'result_payload' => $payload,
            'summary_payload' => [],
            'warnings_payload' => [],
            'status' => 'completed',
            'generated_at' => now(),
        ]);
    }

    private function normalizedData(
        ?string $lagnam,
        ?string $rasi,
        ?string $nakshatra,
        ?string $currentMahadasha,
        ?int $house,
        string $birthDate = '1973-12-16'
    ): array {
        $planets = [];

        if ($currentMahadasha !== null && $house !== null) {
            $planets[] = [
                'id' => app(\App\Services\Prediction\AstrologyNameMapper::class)->planetId($currentMahadasha),
                'name' => $currentMahadasha,
                'house' => $house,
            ];
        }

        return [
            'birth' => [
                'date_of_birth' => $birthDate,
                'time_of_birth' => '06:45',
                'place_of_birth' => 'Chennai, Tamil Nadu',
                'latitude' => 13.0827,
                'longitude' => 80.2707,
            ],
            'identity' => [
                'lagnam' => $lagnam,
                'rasi' => $rasi,
                'nakshatra' => $nakshatra,
            ],
            'dasha' => [
                'current_major_lord' => $currentMahadasha,
                'current_minor_lord' => 'Moon',
                'major' => [
                    ['planet' => 'Venus', 'planet_id' => 5, 'start' => '8-7-2019 18:21', 'end' => '8-7-2039 18:21'],
                ],
            ],
            'planets' => $planets,
        ];
    }

    private function fakeNakshatraLagnamWorkbookWithRows(array $rows): void
    {
        $directory = $this->predictionDataDirectory();

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Rule001');
        $sheet->fromArray($rows);

        (new Xlsx($spreadsheet))->save($directory.DIRECTORY_SEPARATOR.'nakshatra_lagnam.xlsx');
    }

    private function createAgeWorkbook(string $period, string $fileName, string $sheetName, array $rows): void
    {
        $directory = $this->predictionDataDirectory().DIRECTORY_SEPARATOR.$period;

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($sheetName);
        $sheet->fromArray($rows);

        (new Xlsx($spreadsheet))->save($directory.DIRECTORY_SEPARATOR.$fileName);
    }

    private function hierarchyRows(string $yearHeader, array $videoIds): array
    {
        return [
            ['VENUS DASA', 'POSITION'],
            ['LAGNAM', ''],
            ['MESHAM', ''],
            ['THULAM', '6', $yearHeader, 'WRONG_RASI'],
            array_merge(['MEENAM', '6', $yearHeader], $videoIds, ['2020', 'WRONG_YEAR']),
            ['RISHABAM', ''],
            ['MEENAM', '6', $yearHeader, 'WRONG_LAGNAM'],
        ];
    }

    private function predictionDataDirectory(): string
    {
        return storage_path('framework/testing/admin-prediction-inspect-data');
    }
}
