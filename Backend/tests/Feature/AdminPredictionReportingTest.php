<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\PaymentTransaction;
use App\Models\PredictionEntitlement;
use App\Models\PredictionFeedback;
use App\Models\PredictionProduct;
use App\Models\PredictionRequest;
use App\Models\PredictionResult;
use App\Models\User;
use App\Services\AstrologyApiService;
use App\Services\Prediction\AgeWorkbookResolver;
use App\Services\Prediction\Excel\ExcelReader;
use App\Services\Prediction\PredictionEngineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class AdminPredictionReportingTest extends TestCase
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

    public function test_admin_past_prediction_detail_returns_excel_trace_and_sorted_videos(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create(['name' => 'Test User', 'email' => 'test@example.com']);
        $request = $this->predictionRequest($user, 'past');
        $result = $this->predictionResult($request, 'past', [
            ['age' => 42, 'system_year' => 2025, 'major_dasha' => 'Venus', 'dasha_year_label' => '2020-2039', 'workbook' => '42.xlsx', 'sheet' => 'VENUS', 'position' => 4, 'excel_row_number' => 22, 'excel_cell' => 'T22', 'matched_year' => '2025', 'video_ids' => ['P42']],
            ['age' => 41, 'system_year' => 2024, 'major_dasha' => 'Sun', 'dasha_year_label' => '2020-2026', 'workbook' => '41.xlsx', 'sheet' => 'SUN', 'position' => 3, 'excel_row_number' => 18, 'excel_cell' => 'S18', 'matched_year' => '2024', 'video_ids' => ['P41']],
        ]);

        PredictionFeedback::query()->create([
            'prediction_request_id' => $request->id,
            'prediction_result_id' => $result->id,
            'prediction_type' => 'past',
            'rating' => 5,
            'accuracy_band' => 'high',
            'comments' => 'Good',
            'submitted_at' => now(),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/prediction-requests/{$request->id}/details?type=past&result_id={$result->id}")
            ->assertOk()
            ->assertJsonPath('data.rating.label', '5 / 5')
            ->assertJsonCount(1, 'data.identity_video')
            ->assertJsonPath('data.identity_video.0.excel_file', 'nakshatra_lagnam.xlsx')
            ->assertJsonPath('data.identity_video.0.rasi', 'Aries')
            ->assertJsonPath('data.identity_video.0.nakshatra', 'Bharani')
            ->assertJsonPath('data.identity_video.0.lagnam', 'Leo')
            ->assertJsonPath('data.identity_video.0.excel_row_number', 7)
            ->assertJsonPath('data.identity_video.0.video_id', 'INTRO_001')
            ->assertJsonPath('data.astrology.identity.rasi', 'Aries')
            ->assertJsonPath('data.astrology.identity.nakshatra', 'Bharani')
            ->assertJsonPath('data.astrology.identity.lagnam', 'Leo')
            ->assertJsonPath('data.astrology.planets.0.name', 'Sun')
            ->assertJsonPath('data.prediction_data.0.age', 41)
            ->assertJsonPath('data.prediction_data.0.excel_file', '41.xlsx')
            ->assertJsonPath('data.prediction_data.0.sheet_name', 'SUN')
            ->assertJsonPath('data.prediction_data.0.excel_row_number', 18)
            ->assertJsonPath('data.prediction_data.0.excel_cell', 'S18')
            ->assertJsonPath('data.prediction_data.0.dasha', 'Sun')
            ->assertJsonMissingPath('data.identity_video.0.match_position')
            ->assertJsonMissingPath('data.prediction_data.0.match_position')
            ->assertJsonMissingPath('data.prediction_data.0.bhukti')
            ->assertJsonMissingPath('data.viewer_url')
            ->assertJsonPath('data.videos.0.video_id', 'P42')
            ->assertJsonPath('data.videos.1.video_id', 'P41');
    }

    public function test_missing_prediction_trace_fields_use_short_not_recorded_values(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $request = $this->predictionRequest($user, 'past');
        $result = $this->predictionResult($request, 'past', [
            ['age' => 45, 'system_year' => 2026, 'video_ids' => ['OLD_45']],
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/prediction-requests/{$request->id}/details?type=past&result_id={$result->id}")
            ->assertOk()
            ->assertJsonPath('data.prediction_data.0.excel_row_number', 'Not recorded')
            ->assertJsonPath('data.prediction_data.0.dasha', 'Not recorded')
            ->assertJsonPath('data.identity_video.0.excel_row_number', 7);
    }

    public function test_admin_prediction_detail_uses_identity_video_column_when_payload_trace_is_missing(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $request = $this->predictionRequest($user, 'past');
        $result = PredictionResult::query()->create([
            'prediction_request_id' => $request->id,
            'prediction_type' => 'past',
            'identity_video_id' => 'INTRO_COLUMN',
            'result_payload' => [
                'prediction_videos' => [
                    ['age' => 45, 'system_year' => 2026, 'video_ids' => ['OLD_45']],
                ],
            ],
            'summary_payload' => [],
            'warnings_payload' => [],
            'status' => 'completed',
            'generated_at' => now(),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/prediction-requests/{$request->id}/details?type=past&result_id={$result->id}")
            ->assertOk()
            ->assertJsonPath('data.identity_video.0.video_id', 'INTRO_COLUMN')
            ->assertJsonPath('data.identity_video.0.excel_file', 'nakshatra_lagnam.xlsx')
            ->assertJsonPath('data.identity_video.0.rasi', 'Aries')
            ->assertJsonPath('data.identity_video.0.nakshatra', 'Bharani')
            ->assertJsonPath('data.identity_video.0.lagnam', 'Leo');
    }

    public function test_admin_prediction_detail_recovers_identity_video_when_stored_nakshatra_is_swati(): void
    {
        $this->fakeNakshatraLagnamWorkbookWithRows([
            ['LAGNAM', 'RASI', 'NAKSHATRA', 'VIDEO'],
            ['MESHAM', 'THULAM', 'Chitra', '19'],
            ['MESHAM', 'THULAM', 'Swathi    ', '20'],
            ['MESHAM', 'THULAM', 'Vishakha  ', '21'],
        ]);

        $admin = $this->admin();
        $user = User::factory()->create();
        $request = $this->predictionRequest($user, 'past', [
            'identity' => [
                'lagnam' => 'Aries',
                'rasi' => 'Libra',
                'nakshatra' => 'Swati',
            ],
        ]);
        $result = PredictionResult::query()->create([
            'prediction_request_id' => $request->id,
            'prediction_type' => 'past',
            'identity_video_id' => null,
            'result_payload' => [
                'identity_video' => null,
                'prediction_videos' => [
                    ['age' => 20, 'system_year' => 2017, 'video_ids' => ['471']],
                    ['age' => 28, 'system_year' => 2026, 'video_ids' => ['528']],
                ],
            ],
            'summary_payload' => [],
            'warnings_payload' => [],
            'status' => 'completed',
            'generated_at' => now(),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/prediction-requests/{$request->id}/details?type=past&result_id={$result->id}")
            ->assertOk()
            ->assertJsonPath('data.identity_video.0.video_id', '20')
            ->assertJsonPath('data.identity_video.0.excel_file', 'nakshatra_lagnam.xlsx')
            ->assertJsonPath('data.identity_video.0.sheet_name', 'Rule001')
            ->assertJsonPath('data.identity_video.0.lagnam', 'MESHAM')
            ->assertJsonPath('data.identity_video.0.rasi', 'THULAM')
            ->assertJsonPath('data.identity_video.0.nakshatra', 'Swathi')
            ->assertJsonPath('data.identity_video.0.excel_row_number', 3)
            ->assertJsonPath('data.prediction_data.0.video_id', '471')
            ->assertJsonPath('data.prediction_data.1.video_id', '528');

        $result->refresh();
        $this->assertSame('20', $result->identity_video_id);
        $this->assertSame('20', data_get($result->result_payload, 'identity_video.video_id'));
        $this->assertSame('nakshatra_lagnam.xlsx', data_get($result->result_payload, 'identity_video.source.file'));
        $this->assertSame(3, data_get($result->result_payload, 'identity_video.source.excel_row_number'));
        $this->assertSame(['471'], data_get($result->result_payload, 'prediction_videos.0.video_ids'));
        $this->assertSame(['528'], data_get($result->result_payload, 'prediction_videos.1.video_ids'));
        $this->assertNull($result->result_payload['identity_video']['source']['warning'] ?? null);
    }

    public function test_admin_prediction_detail_recovers_identity_video_trace_for_old_result(): void
    {
        $this->fakeNakshatraLagnamWorkbook();
        $admin = $this->admin();
        $user = User::factory()->create();
        $request = $this->predictionRequest($user, 'past');
        $result = PredictionResult::query()->create([
            'prediction_request_id' => $request->id,
            'prediction_type' => 'past',
            'identity_video_id' => null,
            'result_payload' => [
                'prediction_videos' => [
                    ['age' => 45, 'system_year' => 2026, 'video_ids' => ['OLD_45']],
                ],
            ],
            'summary_payload' => [],
            'warnings_payload' => [],
            'status' => 'completed',
            'generated_at' => now(),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/prediction-requests/{$request->id}/details?type=past&result_id={$result->id}")
            ->assertOk()
            ->assertJsonPath('data.identity_video.0.video_id', '34')
            ->assertJsonPath('data.identity_video.0.excel_file', 'nakshatra_lagnam.xlsx')
            ->assertJsonPath('data.identity_video.0.sheet_name', 'Rule001')
            ->assertJsonPath('data.identity_video.0.nakshatra', 'Bharani')
            ->assertJsonPath('data.identity_video.0.excel_row_number', 2)
            ->assertJsonPath('data.prediction_data.0.video_id', 'OLD_45');

        $result->refresh();
        $this->assertSame('34', $result->identity_video_id);
        $this->assertSame('34', data_get($result->result_payload, 'identity_video.video_id'));
    }

    public function test_admin_prediction_detail_uses_authenticated_astrology_payload_without_viewer_url(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $request = $this->predictionRequest($user, 'past');
        $result = $this->predictionResult($request, 'past', [
            ['age' => 45, 'system_year' => 2026, 'video_ids' => ['P45']],
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/prediction-requests/{$request->id}/details?type=past&result_id={$result->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.viewer_url')
            ->assertJsonPath('data.astrology.identity.rasi', 'Aries')
            ->assertJsonPath('data.astrology.planets.0.name', 'Sun');
    }

    public function test_admin_future_prediction_detail_is_entitlement_specific(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $request = $this->predictionRequest($user, 'future');
        $product = PredictionProduct::query()->create([
            'code' => 'future-one',
            'name' => 'Future One',
            'prediction_type' => 'future',
            'price_inclusive_tax_minor' => 10000,
            'currency' => 'INR',
            'tax_inclusive' => true,
            'is_active' => true,
        ]);
        $payment = PaymentTransaction::query()->create([
            'prediction_request_id' => $request->id,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'provider' => 'razorpay',
            'currency' => 'INR',
            'amount_minor' => 10000,
            'tax_inclusive' => true,
            'status' => 'CAPTURED',
        ]);
        $secondPayment = PaymentTransaction::query()->create([
            'prediction_request_id' => $request->id,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'provider' => 'razorpay',
            'currency' => 'INR',
            'amount_minor' => 10000,
            'tax_inclusive' => true,
            'status' => 'CAPTURED',
        ]);
        $firstEntitlement = $this->entitlement($request, $user, $product, $payment, 2027, 2027);
        $secondEntitlement = $this->entitlement($request, $user, $product, $secondPayment, 2028, 2028);
        $firstResult = $this->predictionResult($request, 'future', [
            ['age' => 58, 'system_year' => 2027, 'workbook' => 'FUTURE_51 TO 60.xlsx', 'sheet' => 'VENUS', 'matched_year' => '2027', 'video_ids' => ['F58']],
        ], $firstEntitlement);
        $this->predictionResult($request, 'future', [
            ['age' => 59, 'system_year' => 2028, 'workbook' => 'FUTURE_51 TO 60.xlsx', 'sheet' => 'VENUS', 'matched_year' => '2028', 'video_ids' => ['F59']],
        ], $secondEntitlement);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/prediction-requests/{$request->id}/details?type=future&entitlement_id={$firstEntitlement->id}&result_id={$firstResult->id}")
            ->assertOk()
            ->assertJsonPath('data.entitlement.id', $firstEntitlement->id)
            ->assertJsonPath('data.period.label', '2027-2027')
            ->assertJsonMissingPath('data.viewer_url')
            ->assertJsonPath('data.astrology.identity.rasi', 'Aries')
            ->assertJsonPath('data.videos.0.video_id', 'F58')
            ->assertJsonMissingPath('data.videos.1');
    }

    public function test_prediction_detail_requires_admin_authentication(): void
    {
        $user = User::factory()->create();
        $request = $this->predictionRequest($user, 'past');

        $this->getJson("/api/admin/prediction-requests/{$request->id}/details?type=past")
            ->assertUnauthorized();
    }

    public function test_admin_prediction_detail_reads_stored_result_without_engine_or_excel_calls(): void
    {
        $engine = $this->mock(PredictionEngineService::class);
        $engine->shouldNotReceive('preparePredictionTimeline');
        $engine->shouldNotReceive('preparePastPrediction');
        $this->mock(AgeWorkbookResolver::class)->shouldNotReceive('resolve');
        $this->mock(ExcelReader::class)->shouldNotReceive('readWorkbook');
        $this->mock(AstrologyApiService::class)->shouldNotReceive('fetchPastPredictionBundle');

        $admin = $this->admin();
        $user = User::factory()->create();
        $request = $this->predictionRequest($user, 'past');
        $result = $this->predictionResult($request, 'past', [
            ['age' => 41, 'system_year' => 2024, 'workbook' => '41.xlsx', 'sheet' => 'SUN', 'video_ids' => ['P41']],
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/prediction-requests/{$request->id}/details?type=past&result_id={$result->id}")
            ->assertOk()
            ->assertJsonPath('data.videos.0.video_id', 'P41');
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

    private function predictionRequest(User $user, string $type, array $identityOverrides = []): PredictionRequest
    {
        $identity = array_merge(
            ['rasi' => 'Aries', 'nakshatra' => 'Bharani', 'lagnam' => 'Leo'],
            $identityOverrides['identity'] ?? []
        );

        return PredictionRequest::query()->create([
            'request_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'chart_fingerprint' => hash('sha256', $user->email),
            'prediction_type' => $type,
            'status' => 'completed',
            'full_name' => $user->name,
            'date_of_birth' => '1984-01-01',
            'time_of_birth' => '10:30',
            'timezone' => 'Asia/Kolkata',
            'place_of_birth' => 'Chennai',
            'country' => 'India',
            'country_code' => 'IN',
            'latitude' => 13.0827,
            'longitude' => 80.2707,
            'email' => $user->email,
            'birth_payload' => ['day' => 1, 'month' => 1, 'year' => 1984],
            'raw_astrology_response' => ['provider' => 'stored'],
            'normalized_astrology_data' => [
                'identity' => $identity,
                'value' => [
                    'identity' => $identity,
                    'planets' => [['name' => 'Sun', 'sign' => 'Aries']],
                ],
            ],
            'submitted_at' => now(),
            'completed_at' => now(),
        ]);
    }

    private function predictionResult(PredictionRequest $request, string $type, array $videos, ?PredictionEntitlement $entitlement = null): PredictionResult
    {
        return PredictionResult::query()->create([
            'prediction_request_id' => $request->id,
            'user_id' => $entitlement?->user_id,
            'prediction_entitlement_id' => $entitlement?->id,
            'prediction_type' => $type,
            'result_payload' => [
                'identity_video' => [
                    'video_id' => 'INTRO_001',
                    'source' => [
                        'file' => 'nakshatra_lagnam.xlsx',
                        'sheet' => 'Rule001',
                        'nakshatra' => 'Bharani',
                        'lagnam' => 'Leo',
                        'excel_row_number' => 7,
                        'status' => 'SUCCESS',
                    ],
                ],
                'prediction_videos' => $videos,
            ],
            'summary_payload' => [],
            'warnings_payload' => [],
            'status' => 'completed',
            'generated_at' => now(),
        ]);
    }

    private function entitlement(PredictionRequest $request, User $user, PredictionProduct $product, PaymentTransaction $payment, int $startYear, int $endYear): PredictionEntitlement
    {
        return PredictionEntitlement::query()->create([
            'prediction_request_id' => $request->id,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'payment_transaction_id' => $payment->id,
            'prediction_type' => 'future',
            'purchased_years' => 1,
            'access_start_year' => $startYear,
            'access_end_year' => $endYear,
            'start_age' => $startYear - 1969,
            'end_age' => $endYear - 1969,
            'allocated_year_count' => 1,
            'starts_at' => now(),
            'expires_at' => now()->addYear(),
            'purchased_at' => now(),
            'status' => 'ACTIVE',
        ]);
    }

    private function predictionDataDirectory(): string
    {
        return storage_path('framework/testing/admin-prediction-data');
    }

    private function fakeNakshatraLagnamWorkbook(): void
    {
        $this->fakeNakshatraLagnamWorkbookWithRows([
            ['Nakshatra', 'Rasi', 'Lagnam', 'VideoId'],
            ['Bharani', 'Aries', 'Leo', '34'],
        ]);
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
}
