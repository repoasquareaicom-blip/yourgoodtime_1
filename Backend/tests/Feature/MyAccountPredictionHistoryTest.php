<?php

namespace Tests\Feature;

use App\Models\PaymentTransaction;
use App\Models\PredictionEntitlement;
use App\Models\PredictionProduct;
use App\Models\PredictionRequest;
use App\Models\PredictionResult;
use App\Models\User;
use App\Services\AstrologyApiService;
use App\Services\Prediction\AgeWorkbookResolver;
use App\Services\Prediction\PredictionEngineService;
use App\Services\Prediction\PredictionTimelineGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class MyAccountPredictionHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 7, 29, 12, 0, 0, 'Asia/Kolkata'));
        File::deleteDirectory($this->predictionDataDirectory());
        config([
            'prediction.future_years' => 5,
            'prediction.excel_path' => $this->predictionDataDirectory(),
        ]);
        $this->fakeFutureWorkbook(['2006-2010', '2011-2015']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_unauthenticated_user_cannot_access_my_account_predictions(): void
    {
        $this->getJson('/api/my-account/predictions')
            ->assertUnauthorized();
    }

    public function test_past_summary_returns_engine_matching_range_without_yearly_history(): void
    {
        $user = User::factory()->create(['email' => 'ravi@example.com']);
        $predictionRequest = $this->predictionRequest([
            'email' => $user->email,
            'date_of_birth' => '1976-01-01',
        ]);

        $timeline = app(PredictionTimelineGenerator::class)->generate('past', CarbonImmutable::now('Asia/Kolkata'), $predictionRequest->date_of_birth);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/my-account/predictions')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.identity.name', 'Ravi Kumar')
            ->assertJsonPath('data.identity.email', $user->email)
            ->assertJsonPath('data.identity.submitted_details.date_of_birth', '1976-01-01')
            ->assertJsonPath('data.identity.submitted_details.time_of_birth', '06:45')
            ->assertJsonPath('data.identity.submitted_details.place_of_birth', 'Chennai')
            ->assertJsonPath('data.identity.submitted_details.marriage_status', 'married')
            ->assertJsonPath('data.identity.astrology.lagnam', 'Aries')
            ->assertJsonPath('data.identity.astrology.nakshatra', 'Swati')
            ->assertJsonPath('data.identity.astrology.rasi', 'Pisces')
            ->assertJsonPath('data.past.available', true)
            ->assertJsonPath('data.past.start_year', $timeline['from_year'])
            ->assertJsonPath('data.past.end_year', $timeline['to_year'])
            ->assertJsonPath('data.past.action', 'watch')
            ->assertJsonMissingPath('data.past.years')
            ->assertJsonMissingPath('data.past.video_count')
            ->assertJsonMissingPath('data.past.generated_at');
    }

    public function test_past_availability_uses_successful_prediction_request_without_stored_result_or_profile(): void
    {
        $user = User::factory()->create(['email' => 'ravi@example.com']);
        $this->predictionRequest([
            'email' => 'RAVI@EXAMPLE.COM',
            'date_of_birth' => '1976-01-01',
        ]);

        $this->assertDatabaseCount('prediction_results', 0);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/my-account/predictions')
            ->assertOk()
            ->assertJsonPath('data.past.available', true)
            ->assertJsonPath('data.past.action', 'watch');
    }

    public function test_user_without_usable_prediction_request_gets_past_unavailable_get_action(): void
    {
        $user = User::factory()->create(['email' => 'new@example.com']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/my-account/predictions')
            ->assertOk()
            ->assertJsonPath('data.identity.email', $user->email)
            ->assertJsonPath('data.identity.submitted_details', null)
            ->assertJsonCount(0, 'data.identity.astrology')
            ->assertJsonPath('data.past.available', false)
            ->assertJsonPath('data.past.action', 'get');
    }

    public function test_prediction_history_endpoint_does_not_invoke_engine_astrology_api_or_workbook_resolver(): void
    {
        $this->mock(PredictionEngineService::class, function ($mock): void {
            $mock->shouldNotReceive('preparePredictionTimeline');
            $mock->shouldNotReceive('preparePastPrediction');
            $mock->shouldReceive('previewFutureYear')->andReturn([
                'prediction' => ['status' => 'SUCCESS'],
                'rule_data' => [
                    'sequence_base_year' => 1998,
                    'dasha_start_effective_year' => 2019,
                    'dasha_end_effective_year' => 2039,
                    'matched_values' => [
                        'matched_year' => '2006-2010',
                        'position' => 6,
                        'video_ids' => ['FUTURE_001'],
                    ],
                    'excel_file' => 'FUTURE_41 TO 60.xlsx',
                    'worksheet' => 'VENUS',
                    'matched_row' => 4,
                    'year_column' => 'C',
                ],
                'timeline' => null,
            ]);
        });
        $this->mock(AstrologyApiService::class, function ($mock): void {
            $mock->shouldNotReceive('buildBirthPayload');
            $mock->shouldNotReceive('fetchPastPredictionBundle');
            $mock->shouldNotReceive('searchPlaces');
        });
        $this->mock(AgeWorkbookResolver::class, function ($mock): void {
            $mock->shouldNotReceive('resolve');
            $mock->shouldNotReceive('resolveExcelDirectory');
        });

        $user = User::factory()->create(['email' => 'ravi@example.com']);
        $this->predictionRequest([
            'email' => $user->email,
            'date_of_birth' => '1976-01-01',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/my-account/predictions')
            ->assertOk()
            ->assertJsonPath('data.past.available', true);
    }

    public function test_future_ranges_are_owned_by_authenticated_user_only(): void
    {
        $owner = User::factory()->create(['email' => 'owner@example.com']);
        $other = User::factory()->create(['email' => 'other@example.com']);
        $product = $this->futureProduct();
        $ownerRequest = $this->predictionRequest(['email' => $owner->email, 'date_of_birth' => '1976-01-01']);
        $otherRequest = $this->predictionRequest(['email' => $other->email, 'date_of_birth' => '1976-01-01']);

        $ownerEntitlement = $this->futureEntitlement($owner, $ownerRequest, $product, 2027, 2031);
        $this->futureEntitlement($other, $otherRequest, $product, 2032, 2036);
        PredictionResult::query()->create([
            'prediction_request_id' => $ownerRequest->id,
            'user_id' => $owner->id,
            'prediction_entitlement_id' => $ownerEntitlement->id,
            'prediction_type' => 'future',
            'identity_video_id' => 'INTRO_001',
            'result_payload' => ['prediction_videos' => []],
            'summary_payload' => [],
            'warnings_payload' => [],
            'status' => 'completed',
            'generated_at' => now(),
        ]);

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/my-account/predictions')
            ->assertOk()
            ->assertJsonPath('data.future.purchased', true)
            ->assertJsonCount(1, 'data.future.ranges')
            ->assertJsonPath('data.future.ranges.0.start_year', 2027)
            ->assertJsonPath('data.future.ranges.0.end_year', 2031)
            ->assertJsonPath('data.future.ranges.0.has_result', true)
            ->assertJsonMissingPath('data.future.ranges.0.payment_transaction_id')
            ->assertJsonMissingPath('data.future.ranges.0.price');
    }

    public function test_purchased_future_is_returned_even_when_past_is_unavailable_and_result_is_missing(): void
    {
        $user = User::factory()->create(['email' => 'future-only@example.com']);
        $product = $this->futureProduct();
        $request = $this->predictionRequest([
            'email' => 'different@example.com',
            'date_of_birth' => '1976-01-01',
        ]);
        $this->futureEntitlement($user, $request, $product, 2027, 2031);

        $this->assertDatabaseCount('prediction_results', 0);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/my-account/predictions')
            ->assertOk()
            ->assertJsonPath('data.past.available', false)
            ->assertJsonPath('data.future.purchased', true)
            ->assertJsonCount(1, 'data.future.ranges')
            ->assertJsonPath('data.future.ranges.0.start_year', 2027)
            ->assertJsonPath('data.future.ranges.0.end_year', 2031)
            ->assertJsonPath('data.future.ranges.0.has_result', false);
    }

    public function test_future_summary_returns_only_strictly_upcoming_years(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2027, 1, 2, 12, 0, 0, 'Asia/Kolkata'));
        $user = User::factory()->create(['email' => 'ravi@example.com']);
        $product = $this->futureProduct();
        $predictionRequest = $this->predictionRequest([
            'email' => $user->email,
            'date_of_birth' => '1976-01-01',
        ]);
        $this->futureEntitlement($user, $predictionRequest, $product, 2027, 2031);
        $this->futureEntitlement($user, $predictionRequest, $product, 2024, 2027);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/my-account/predictions')
            ->assertOk()
            ->assertJsonPath('data.future.purchased', true)
            ->assertJsonCount(1, 'data.future.ranges')
            ->assertJsonPath('data.future.ranges.0.start_year', 2028)
            ->assertJsonPath('data.future.ranges.0.end_year', 2031);
    }

    public function test_no_purchase_state_returns_existing_next_allocation_logic(): void
    {
        $user = User::factory()->create(['email' => 'ravi@example.com']);
        $this->predictionRequest([
            'email' => $user->email,
            'date_of_birth' => '1976-01-01',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/my-account/predictions')
            ->assertOk()
            ->assertJsonPath('data.future.purchased', false)
            ->assertJsonCount(0, 'data.future.ranges')
            ->assertJsonPath('data.future.next_purchase.available', true)
            ->assertJsonPath('data.future.next_purchase.start_year', 2027)
            ->assertJsonPath('data.future.next_purchase.end_year', 2031);
    }

    public function test_next_future_range_advances_after_existing_entitlement(): void
    {
        $user = User::factory()->create(['email' => 'ravi@example.com']);
        $product = $this->futureProduct();
        $predictionRequest = $this->predictionRequest([
            'email' => $user->email,
            'date_of_birth' => '1976-01-01',
        ]);
        $this->futureEntitlement($user, $predictionRequest, $product, 2027, 2031);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/my-account/predictions')
            ->assertOk()
            ->assertJsonPath('data.future.next_purchase.available', true)
            ->assertJsonPath('data.future.next_purchase.start_year', 2032)
            ->assertJsonPath('data.future.next_purchase.end_year', 2036);
    }

    public function test_expired_future_entitlement_is_not_active_but_result_remains_stored(): void
    {
        $user = User::factory()->create(['email' => 'ravi@example.com']);
        $product = $this->futureProduct();
        $predictionRequest = $this->predictionRequest([
            'email' => $user->email,
            'date_of_birth' => '1976-01-01',
        ]);
        $expiredEntitlement = $this->futureEntitlement($user, $predictionRequest, $product, 2024, 2025);
        $storedResult = PredictionResult::query()->create([
            'prediction_request_id' => $predictionRequest->id,
            'user_id' => $user->id,
            'prediction_entitlement_id' => $expiredEntitlement->id,
            'prediction_type' => 'future',
            'identity_video_id' => 'INTRO_OLD',
            'result_payload' => ['prediction_videos' => [['video_ids' => ['OLD_001']]]],
            'summary_payload' => [],
            'warnings_payload' => [],
            'status' => 'completed',
            'generated_at' => now(),
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/my-account/predictions')
            ->assertOk()
            ->assertJsonPath('data.future.purchased', false)
            ->assertJsonCount(0, 'data.future.ranges')
            ->assertJsonPath('data.future.next_purchase.start_year', 2027);

        $this->assertDatabaseHas('prediction_results', [
            'id' => $storedResult->id,
            'prediction_entitlement_id' => $expiredEntitlement->id,
            'status' => 'completed',
        ]);
    }

    private function predictionRequest(array $overrides = []): PredictionRequest
    {
        $birthDate = $overrides['date_of_birth'] ?? '1976-01-01';

        return PredictionRequest::query()->create(array_merge([
            'request_uuid' => (string) Str::uuid(),
            'chart_fingerprint' => hash('sha256', (string) Str::uuid()),
            'prediction_type' => 'past',
            'status' => 'completed',
            'full_name' => 'Ravi Kumar',
            'gender' => 'male',
            'date_of_birth' => $birthDate,
            'time_of_birth' => '06:45',
            'timezone' => 'Asia/Kolkata',
            'place_of_birth' => 'Chennai',
            'country' => 'India',
            'country_code' => 'IN',
            'latitude' => 13.0827,
            'longitude' => 80.2707,
            'marriage_status' => 'married',
            'email' => 'ravi@example.com',
            'birth_payload' => ['day' => 1, 'month' => 1, 'year' => (int) substr($birthDate, 0, 4), 'hour' => 6, 'min' => 45],
            'raw_astrology_response' => ['astro_details' => ['ascendant' => 'Aries']],
            'normalized_astrology_data' => $this->normalizedData($birthDate),
            'api_call_count' => 5,
            'cache_hit' => false,
            'submitted_at' => now(),
            'completed_at' => now(),
        ], $overrides));
    }

    private function normalizedData(string $birthDate): array
    {
        return [
            'birth' => [
                'date_of_birth' => $birthDate,
                'time_of_birth' => '06:45',
                'place_of_birth' => 'Chennai',
                'latitude' => 13.0827,
                'longitude' => 80.2707,
            ],
            'identity' => [
                'lagnam' => 'Aries',
                'rasi' => 'Pisces',
                'nakshatra' => 'Swati',
            ],
            'dasha' => [
                'current_major_lord' => 'Venus',
                'current_minor_lord' => 'Moon',
                'major' => [
                    ['planet' => 'Venus', 'start' => '8-7-2019 18:21', 'end' => '8-7-2039 18:21'],
                ],
            ],
            'planets' => [
                ['id' => 5, 'name' => 'Venus', 'house' => 6],
            ],
        ];
    }

    private function futureProduct(array $overrides = []): PredictionProduct
    {
        return PredictionProduct::query()->create(array_merge([
            'code' => 'FUTURE_PREDICTION',
            'prediction_type' => 'future',
            'name' => 'Future Prediction',
            'description' => 'Unlock future guidance.',
            'price_inclusive_tax_minor' => 60000,
            'currency' => 'INR',
            'tax_inclusive' => true,
            'is_active' => true,
            'metadata' => [],
        ], $overrides));
    }

    private function futureEntitlement(User $user, PredictionRequest $request, PredictionProduct $product, int $startYear, int $endYear): PredictionEntitlement
    {
        $transaction = PaymentTransaction::query()->create([
            'prediction_request_id' => $request->id,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'provider' => 'razorpay',
            'currency' => 'INR',
            'amount_minor' => $product->price_inclusive_tax_minor,
            'tax_inclusive' => true,
            'status' => 'CAPTURED',
            'provider_order_id' => 'order_'.Str::random(8),
            'provider_payment_id' => 'pay_'.Str::random(8),
            'paid_at' => now(),
        ]);

        return PredictionEntitlement::query()->create([
            'prediction_request_id' => $request->id,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'payment_transaction_id' => $transaction->id,
            'prediction_type' => 'future',
            'purchased_years' => $endYear - $startYear + 1,
            'access_start_year' => $startYear,
            'access_end_year' => $endYear,
            'start_age' => $startYear - 2026 + 50,
            'end_age' => $endYear - 2026 + 50,
            'allocated_year_count' => $endYear - $startYear + 1,
            'starts_at' => now(),
            'expires_at' => CarbonImmutable::create($endYear, 12, 31, 23, 59, 59, 'Asia/Kolkata'),
            'purchased_at' => now(),
            'status' => 'ACTIVE',
        ]);
    }

    private function fakeFutureWorkbook(array $yearHeaders): void
    {
        $directory = $this->predictionDataDirectory().DIRECTORY_SEPARATOR.'future';

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $row = ['MEENAM', '6'];
        foreach ($yearHeaders as $index => $yearHeader) {
            $row[] = $yearHeader;
            $row[] = 'FUTURE_'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT);
        }

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->setTitle('VENUS');
        $spreadsheet->getActiveSheet()->fromArray([
            ['VENUS DASA', 'POSITION'],
            ['LAGNAM', ''],
            ['MESHAM', ''],
            $row,
        ]);

        (new Xlsx($spreadsheet))->save($directory.DIRECTORY_SEPARATOR.'FUTURE_41 TO 60.xlsx');
    }

    private function predictionDataDirectory(): string
    {
        return storage_path('framework/testing/my-account-prediction-data');
    }
}
