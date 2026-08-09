<?php

namespace Tests\Feature;

use App\Models\PredictionRequest;
use App\Models\PaymentTransaction;
use App\Models\PredictionEntitlement;
use App\Models\PredictionProduct;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class PredictionEnginePrepareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        File::deleteDirectory($this->predictionDataDirectory());
        config([
            'prediction.past_years' => 1,
            'prediction.future_years' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_legacy_prepare_past_prediction_validates_request_id(): void
    {
        $this->postJson('/api/predictions/past/prepare', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['request_id']);
    }

    public function test_identity_video_is_matched_by_lagnam_rasi_and_nakshatra(): void
    {
        config(['prediction.debug_response' => true]);
        $this->fakeNakshatraLagnamWorkbookWithRows([
            ['LAGNAM', 'RASI', 'NAKSHATRA', 'VIDEO'],
            ['Mesha', 'Meena', 'Poorvabhadrapada', '34'],
            ['Mesha', 'Thula', 'Swati', 'WRONG_RASI'],
        ]);

        $request = $this->createPredictionRequest([
            'normalized_astrology_data' => $this->normalizedData('Aries', 'Pisces', 'Purva Bhadrapad', 'Venus', 6),
        ]);

        $this->postJson('/api/predictions/past/prepare?debug=true', [
            'request_id' => $request->id,
        ])
            ->assertOk()
            ->assertJsonPath('playlist_items.0.video_id', '34')
            ->assertJsonPath('rules.0.lookup_values.lagnam', 'Aries')
            ->assertJsonPath('rules.0.lookup_values.rasi', 'Pisces')
            ->assertJsonPath('rules.0.lookup_values.nakshatra', 'Purva Bhadrapad')
            ->assertJsonPath('rules.0.matched_row', 2);
    }

    public function test_prediction_video_is_matched_by_lagnam_rasi_position_and_single_sequence_year(): void
    {
        config(['prediction.debug_response' => true]);
        $this->fakeNakshatraLagnamWorkbook('INTRO_001', 'Aries', 'Pisces', 'Swati');
        $this->createAgeWorkbook('past', '51 TO 60.xlsx', 'VENUS', $this->hierarchyRows('2014', ['PRED_2014']));

        $request = $this->requestForAgeTimeline();

        $this->postJson('/api/predictions/past/prepare?debug=true', [
            'prediction_request_id' => $request->id,
            'prediction_type' => 'past',
        ])
            ->assertOk()
            ->assertJsonPath('prediction_videos.0.age', 52)
            ->assertJsonPath('prediction_videos.0.system_year', 2026)
            ->assertJsonPath('prediction_videos.0.excel_sequence_year', 2014)
            ->assertJsonPath('prediction_videos.0.excel_row_number', 5)
            ->assertJsonPath('prediction_videos.0.matched_year', '2014')
            ->assertJsonPath('prediction_videos.0.video_ids.0', 'PRED_2014')
            ->assertJsonPath('rule_results.1.matched_values.rasi', 'MEENAM');
    }

    public function test_same_lagnam_and_position_under_different_rasis_returns_correct_rasi_video(): void
    {
        config(['prediction.debug_response' => true]);
        $this->fakeNakshatraLagnamWorkbook('INTRO_001', 'Aries', 'Pisces', 'Swati');
        $this->createAgeWorkbook('past', '51 TO 60.xlsx', 'VENUS', [
            ['VENUS DASA', 'POSITION'],
            ['LAGNAM', ''],
            ['MESHAM', ''],
            ['THULAM', '6', '2014', 'WRONG_RASI'],
            ['MEENAM', '6', '2014', 'RIGHT_RASI'],
        ]);

        $request = $this->requestForAgeTimeline();

        $this->postJson('/api/predictions/past/prepare', [
            'prediction_request_id' => $request->id,
            'prediction_type' => 'past',
        ])
            ->assertOk()
            ->assertJsonPath('prediction_videos.0.video_ids.0', 'RIGHT_RASI')
            ->assertJsonPath('prediction_videos.0.video_ids.1', null);
    }

    public function test_sequence_year_range_header_is_matched_when_it_contains_sequence_year(): void
    {
        config(['prediction.debug_response' => true]);
        $this->fakeNakshatraLagnamWorkbook('INTRO_001', 'Aries', 'Pisces', 'Swati');
        $this->createAgeWorkbook('past', '51 TO 60.xlsx', 'VENUS', $this->hierarchyRows('2013-2014', ['RANGE_6152']));

        $request = $this->requestForAgeTimeline();

        $this->postJson('/api/predictions/past/prepare?debug=true', [
            'prediction_request_id' => $request->id,
            'prediction_type' => 'past',
        ])
            ->assertOk()
            ->assertJsonPath('prediction_videos.0.matched_year', '2013-2014')
            ->assertJsonPath('prediction_videos.0.video_ids.0', 'RANGE_6152');
    }

    public function test_multiple_video_ids_under_one_year_header_are_returned(): void
    {
        config(['prediction.debug_response' => true]);
        $this->fakeNakshatraLagnamWorkbook('INTRO_001', 'Aries', 'Pisces', 'Swati');
        $this->createAgeWorkbook('past', '51 TO 60.xlsx', 'VENUS', $this->hierarchyRows('2014', ['A_6152', 'B_6153']));

        $request = $this->requestForAgeTimeline();

        $this->postJson('/api/predictions/past/prepare?debug=true', [
            'prediction_request_id' => $request->id,
            'prediction_type' => 'past',
        ])
            ->assertOk()
            ->assertJsonPath('prediction_videos.0.video_ids.0', 'A_6152')
            ->assertJsonPath('prediction_videos.0.video_ids.1', 'B_6153')
            ->assertJsonPath('yearly_predictions.0.playlist_items.0.video_id', 'A_6152')
            ->assertJsonPath('yearly_predictions.0.playlist_items.1.video_id', 'B_6153');
    }

    public function test_past_starts_at_current_completed_age_and_future_starts_at_current_age_plus_one(): void
    {
        config([
            'prediction.past_years' => 10,
            'prediction.future_years' => 10,
        ]);

        $request = $this->requestForAgeTimeline();

        $past = app(\App\Services\Prediction\PredictionEngineService::class)
            ->preparePredictionTimeline($request->id, 'past');
        $future = app(\App\Services\Prediction\PredictionEngineService::class)
            ->preparePredictionTimeline($request->id, 'future');

        $this->assertSame([52, 51, 50, 49, 48, 47, 46, 45, 44, 43], array_map(
            fn ($session): ?int => $session->age,
            $past->yearSessions
        ));
        $this->assertSame([2026, 2025, 2024, 2023, 2022, 2021, 2020, 2019, 2018, 2017], array_map(
            fn ($session): int => $session->targetYear,
            $past->yearSessions
        ));
        $this->assertSame([53, 54, 55, 56, 57, 58, 59, 60], array_map(
            fn ($session): ?int => $session->age,
            $future->yearSessions
        ));
        $this->assertSame([2027, 2028, 2029, 2030, 2031, 2032, 2033, 2034], array_map(
            fn ($session): int => $session->targetYear,
            $future->yearSessions
        ));
        $this->assertSame(10, $future->candidateYears);
        $this->assertSame(8, $future->processedYears);
        $this->assertSame(2, $future->excludedAgeCount);
    }

    public function test_past_age_52_uses_configured_years_as_candidate_count_and_filters_supported_ages(): void
    {
        config(['prediction.past_years' => 40]);

        $request = $this->requestForAgeTimeline();
        $past = app(\App\Services\Prediction\PredictionEngineService::class)
            ->preparePredictionTimeline($request->id, 'past');

        $this->assertSame(40, $past->configuredYears);
        $this->assertSame(40, $past->candidateYears);
        $this->assertSame(37, $past->processedYears);
        $this->assertSame(3, $past->excludedAgeCount);
        $this->assertSame(16, $past->minimumSupportedAge);
        $this->assertSame(60, $past->maximumSupportedAge);
        $this->assertSame(52, $past->yearSessions[0]->age);
        $this->assertSame(2026, $past->yearSessions[0]->targetYear);
        $this->assertSame(16, $past->yearSessions[array_key_last($past->yearSessions)]->age);
        $this->assertSame(1990, $past->yearSessions[array_key_last($past->yearSessions)]->targetYear);
    }

    public function test_future_age_52_uses_configured_years_as_candidate_count_and_filters_supported_ages(): void
    {
        config(['prediction.future_years' => 40]);

        $request = $this->requestForAgeTimeline();
        $future = app(\App\Services\Prediction\PredictionEngineService::class)
            ->preparePredictionTimeline($request->id, 'future');

        $this->assertSame(40, $future->configuredYears);
        $this->assertSame(40, $future->candidateYears);
        $this->assertSame(8, $future->processedYears);
        $this->assertSame(32, $future->excludedAgeCount);
        $this->assertSame(22, $future->minimumSupportedAge);
        $this->assertSame(60, $future->maximumSupportedAge);
        $this->assertSame(53, $future->yearSessions[0]->age);
        $this->assertSame(2027, $future->yearSessions[0]->targetYear);
        $this->assertSame(60, $future->yearSessions[array_key_last($future->yearSessions)]->age);
        $this->assertSame(2034, $future->yearSessions[array_key_last($future->yearSessions)]->targetYear);
    }

    public function test_future_current_age_58_processes_only_ages_59_and_60(): void
    {
        config(['prediction.future_years' => 40]);

        $request = $this->requestForBirthDate('1968-07-25');
        $future = app(\App\Services\Prediction\PredictionEngineService::class)
            ->preparePredictionTimeline($request->id, 'future');

        $this->assertSame(40, $future->configuredYears);
        $this->assertSame(40, $future->candidateYears);
        $this->assertSame(2, $future->processedYears);
        $this->assertSame(38, $future->excludedAgeCount);
        $this->assertSame([59, 60], array_map(fn ($session): ?int => $session->age, $future->yearSessions));
        $this->assertSame([2027, 2028], array_map(fn ($session): int => $session->targetYear, $future->yearSessions));
    }

    public function test_future_current_age_20_excludes_age_21_and_starts_processing_at_22(): void
    {
        config(['prediction.future_years' => 4]);

        $request = $this->requestForBirthDate('2006-07-25');
        $future = app(\App\Services\Prediction\PredictionEngineService::class)
            ->preparePredictionTimeline($request->id, 'future');

        $this->assertSame(4, $future->candidateYears);
        $this->assertSame(3, $future->processedYears);
        $this->assertSame(1, $future->excludedAgeCount);
        $this->assertSame([22, 23, 24], array_map(fn ($session): ?int => $session->age, $future->yearSessions));
        $this->assertSame([2028, 2029, 2030], array_map(fn ($session): int => $session->targetYear, $future->yearSessions));
    }

    public function test_future_current_age_60_has_no_supported_ages_and_keeps_identity_independent(): void
    {
        config(['prediction.future_years' => 3]);
        $this->fakeNakshatraLagnamWorkbook('INTRO_001', 'Aries', 'Pisces', 'Swati');

        $request = $this->requestForBirthDate('1966-07-25');

        $this->postJson('/api/predictions/past/prepare', [
            'prediction_request_id' => $request->id,
            'prediction_type' => 'future',
        ])
            ->assertOk()
            ->assertJsonPath('summary.candidate_years', 3)
            ->assertJsonPath('summary.processed_years', 0)
            ->assertJsonPath('summary.excluded_age_count', 3)
            ->assertJsonPath('summary.status', 'NO_SUPPORTED_AGES')
            ->assertJsonPath('identity_video.video_id', 'INTRO_001')
            ->assertJsonPath('identity_video.source.excel_row_number', 2)
            ->assertJsonCount(0, 'yearly_predictions')
            ->assertJsonCount(0, 'prediction_videos');

        $this->assertDatabaseCount('prediction_rule_logs', 0);
    }

    public function test_past_current_age_65_processes_only_supported_ages_inside_range(): void
    {
        config(['prediction.past_years' => 40]);

        $request = $this->requestForBirthDate('1961-07-25');
        $past = app(\App\Services\Prediction\PredictionEngineService::class)
            ->preparePredictionTimeline($request->id, 'past');

        $this->assertSame(40, $past->candidateYears);
        $this->assertSame(35, $past->processedYears);
        $this->assertSame(5, $past->excludedAgeCount);
        $this->assertSame(60, $past->yearSessions[0]->age);
        $this->assertSame(2021, $past->yearSessions[0]->targetYear);
        $this->assertSame(26, $past->yearSessions[array_key_last($past->yearSessions)]->age);
        $this->assertSame(1987, $past->yearSessions[array_key_last($past->yearSessions)]->targetYear);
    }

    public function test_august_birth_before_birthday_keeps_completed_age_52_but_uses_current_year(): void
    {
        config(['prediction.past_years' => 1]);

        $request = $this->requestForAgeTimeline([
            'date_of_birth' => '1973-08-16',
            'completed_at' => CarbonImmutable::parse('2026-07-27 10:00:00'),
            'submitted_at' => CarbonImmutable::parse('2026-07-27 09:00:00'),
            'normalized_astrology_data' => $this->normalizedData('Aries', 'Pisces', 'Swati', 'Venus', 6),
        ]);

        $past = app(\App\Services\Prediction\PredictionEngineService::class)
            ->preparePredictionTimeline($request->id, 'past');

        $this->assertSame(52, $past->yearSessions[0]->age);
        $this->assertSame(2026, $past->yearSessions[0]->targetYear);
        $this->assertNotSame(1973 + $past->yearSessions[0]->age, $past->yearSessions[0]->targetYear);
    }

    public function test_future_dasha_change_resets_excel_sequence_year_to_1998(): void
    {
        config(['prediction.debug_response' => true, 'prediction.future_years' => 1]);
        $this->fakeNakshatraLagnamWorkbook('INTRO_001', 'Aries', 'Pisces', 'Swati');
        $this->createAgeWorkbook('future', 'FUTURE_51 TO 60.xlsx', 'SUN', $this->hierarchyRows('1998', ['SUN_RESET']));

        $request = $this->requestForAgeTimeline([
            'normalized_astrology_data' => $this->normalizedData('Aries', 'Pisces', 'Swati', 'Venus', 6, [
                ['planet' => 'Venus', 'planet_id' => 5, 'start' => '8-7-2019 18:21', 'end' => '31-12-2026 23:59'],
                ['planet' => 'Sun', 'planet_id' => 0, 'start' => '1-1-2027 00:00', 'end' => '31-12-2031 23:59'],
            ], 'Sun'),
            'completed_at' => CarbonImmutable::parse('2026-07-25 10:00:00'),
        ]);

        $this->postJson('/api/predictions/past/prepare?debug=true', [
            'prediction_request_id' => $request->id,
            'prediction_type' => 'future',
        ])
            ->assertOk()
            ->assertJsonPath('prediction_videos.0.age', 53)
            ->assertJsonPath('prediction_videos.0.system_year', 2027)
            ->assertJsonPath('prediction_videos.0.major_dasha', 'Sun')
            ->assertJsonPath('prediction_videos.0.excel_sequence_year', 1998)
            ->assertJsonPath('prediction_videos.0.excel_row_number', 5)
            ->assertJsonPath('prediction_videos.0.video_ids.0', 'SUN_RESET');
    }

    public function test_mahadasha_system_year_selection_treats_end_year_as_exclusive(): void
    {
        $majorDasha = [
            ['planet' => 'Mercury', 'planet_id' => 3, 'start' => '8-7-2009 18:21', 'end' => '8-7-2019 18:21'],
            ['planet' => 'Venus', 'planet_id' => 5, 'start' => '8-7-2019 18:21', 'end' => '8-7-2035 18:21'],
        ];
        $context = (new \App\Services\Prediction\PredictionContext())->setDasha(['major' => $majorDasha]);

        foreach ([2009, 2010, 2018] as $year) {
            $this->assertSame('Mercury', $context->getMahadashaForSystemYear($year)?->planet);
        }

        foreach ([2019, 2020, 2034] as $year) {
            $this->assertSame('Venus', $context->getMahadashaForSystemYear($year)?->planet);
        }

        $this->assertSame($majorDasha, $context->getDasha()['major']);

        $resolver = app(\App\Services\Prediction\DashaSequenceYearResolver::class);
        $this->assertFalse($resolver->resolveForSystemYear(2019, '8-7-2009 18:21', '8-7-2019 18:21')->isWithinPeriod);
        $this->assertTrue($resolver->resolveForSystemYear(2019, '8-7-2019 18:21', '8-7-2035 18:21')->isWithinPeriod);

        foreach ([2009, 2010, 2018, 2019, 2020, 2034] as $year) {
            $matches = collect($majorDasha)
                ->filter(fn (array $period): bool => $resolver->resolveForSystemYear($year, $period['start'], $period['end'])->isWithinPeriod)
                ->count();

            $this->assertSame(1, $matches);
        }
    }

    public function test_past_boundary_year_uses_next_dasha_for_excel_lookup(): void
    {
        config(['prediction.debug_response' => true, 'prediction.past_years' => 1]);
        $this->fakeNakshatraLagnamWorkbook('INTRO_001', 'Aries', 'Pisces', 'Swati');
        $this->createAgeWorkbook('past', '41 TO 50.xlsx', 'VENUS', $this->hierarchyRows('2007', ['PAST_BOUNDARY_VENUS']));

        $request = $this->requestForAgeTimeline([
            'date_of_birth' => '1973-12-16',
            'completed_at' => CarbonImmutable::parse('2019-07-25 10:00:00'),
            'submitted_at' => CarbonImmutable::parse('2019-07-25 09:00:00'),
            'normalized_astrology_data' => $this->normalizedData('Aries', 'Pisces', 'Swati', 'Venus', 6, [
                ['planet' => 'Mercury', 'planet_id' => 3, 'start' => '8-7-2009 18:21', 'end' => '8-7-2019 18:21'],
                ['planet' => 'Venus', 'planet_id' => 5, 'start' => '8-7-2019 18:21', 'end' => '8-7-2035 18:21'],
            ]),
        ]);

        $this->postJson('/api/predictions/past/prepare?debug=true', [
            'prediction_request_id' => $request->id,
            'prediction_type' => 'past',
        ])
            ->assertOk()
            ->assertJsonPath('prediction_videos.0.system_year', 2019)
            ->assertJsonPath('prediction_videos.0.major_dasha', 'Venus')
            ->assertJsonPath('prediction_videos.0.dasha_start_year', 2019)
            ->assertJsonPath('prediction_videos.0.dasha_end_year', 2035)
            ->assertJsonPath('prediction_videos.0.excel_sequence_year', 2007)
            ->assertJsonPath('prediction_videos.0.video_ids.0', 'PAST_BOUNDARY_VENUS');
    }

    public function test_future_boundary_year_uses_next_dasha_for_excel_lookup(): void
    {
        config(['prediction.debug_response' => true, 'prediction.future_years' => 1]);
        $this->fakeNakshatraLagnamWorkbook('INTRO_001', 'Aries', 'Pisces', 'Swati');
        $this->createAgeWorkbook('future', 'FUTURE_41 TO 50.xlsx', 'VENUS', $this->hierarchyRows('1998', ['FUTURE_BOUNDARY_VENUS']));

        $request = $this->requestForAgeTimeline([
            'date_of_birth' => '1973-12-16',
            'completed_at' => CarbonImmutable::parse('2018-07-25 10:00:00'),
            'submitted_at' => CarbonImmutable::parse('2018-07-25 09:00:00'),
            'normalized_astrology_data' => $this->normalizedData('Aries', 'Pisces', 'Swati', 'Venus', 6, [
                ['planet' => 'Mercury', 'planet_id' => 3, 'start' => '8-7-2009 18:21', 'end' => '8-7-2019 18:21'],
                ['planet' => 'Venus', 'planet_id' => 5, 'start' => '8-7-2019 18:21', 'end' => '8-7-2035 18:21'],
            ]),
        ]);

        $this->postJson('/api/predictions/past/prepare?debug=true', [
            'prediction_request_id' => $request->id,
            'prediction_type' => 'future',
        ])
            ->assertOk()
            ->assertJsonPath('prediction_videos.0.system_year', 2019)
            ->assertJsonPath('prediction_videos.0.major_dasha', 'Venus')
            ->assertJsonPath('prediction_videos.0.dasha_start_year', 2019)
            ->assertJsonPath('prediction_videos.0.dasha_end_year', 2035)
            ->assertJsonPath('prediction_videos.0.excel_sequence_year', 1998)
            ->assertJsonPath('prediction_videos.0.video_ids.0', 'FUTURE_BOUNDARY_VENUS');
    }

    public function test_sequence_base_years_are_prediction_type_specific(): void
    {
        $resolver = app(\App\Services\Prediction\DashaSequenceYearResolver::class);

        $past = $resolver->resolveForSystemYear(2027, '1-1-2027 00:00', '31-12-2031 23:59', 'past');
        $future = $resolver->resolveForSystemYear(2027, '1-1-2027 00:00', '31-12-2031 23:59', 'future');

        $this->assertSame(2007, $past->excelSequenceYear);
        $this->assertSame(1998, $future->excelSequenceYear);
        $this->expectException(\InvalidArgumentException::class);
        app(\App\Services\Prediction\PredictionConfig::class)->dashaExcelSequenceStart('identity');
    }

    public function test_past_generation_excludes_purchased_future_years_and_splits_ranges(): void
    {
        config(['prediction.past_years' => 3]);
        $user = User::factory()->create(['email' => 'ravi@example.com']);
        $request = $this->requestForAgeTimeline(['email' => $user->email]);
        $product = PredictionProduct::query()->create([
            'code' => 'FUTURE_PREDICTION',
            'name' => 'Future Prediction',
            'description' => 'Unlock future guidance.',
            'prediction_type' => 'future',
            'price_inclusive_tax_minor' => 60000,
            'currency' => 'INR',
            'tax_inclusive' => true,
            'is_active' => true,
        ]);
        $transaction = PaymentTransaction::query()->create([
            'prediction_request_id' => $request->id,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'provider' => 'razorpay',
            'currency' => 'INR',
            'amount_minor' => 60000,
            'tax_inclusive' => true,
            'status' => 'CAPTURED',
            'provider_order_id' => 'order_test_exclusion',
        ]);
        PredictionEntitlement::query()->create([
            'prediction_request_id' => $request->id,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'payment_transaction_id' => $transaction->id,
            'prediction_type' => 'future',
            'purchased_years' => 1,
            'access_start_year' => 2025,
            'access_end_year' => 2025,
            'start_age' => 51,
            'end_age' => 51,
            'allocated_year_count' => 1,
            'starts_at' => now(),
            'expires_at' => now()->subDay(),
            'status' => 'EXPIRED',
        ]);

        $this->fakeNakshatraLagnamWorkbook('INTRO_001', 'Aries', 'Pisces', 'Swati');
        $this->createAgeWorkbook('past', '51 TO 60.xlsx', 'VENUS', $this->hierarchyRows('2012-2014', ['RANGE_2024_2026']));

        $this->actingAs($user);
        $timeline = app(\App\Services\Prediction\PredictionEngineService::class)
            ->preparePredictionTimeline($request->id, 'past');
        $response = app(\App\Services\Prediction\PredictionResponseBuilder::class)->buildTimeline($timeline);

        $this->assertSame([2026, 2024], array_map(fn ($session): int => $session->targetYear, $timeline->yearSessions));
        $this->assertSame(1, $response['summary']['excluded_purchased_count']);
        $this->assertSame([2025], $response['summary']['excluded_purchased_years']);
        $this->assertCount(2, $response['prediction_videos']);
        $this->assertSame('2024', $response['prediction_videos'][0]['system_year_label']);
        $this->assertSame('2026', $response['prediction_videos'][1]['system_year_label']);
    }

    public function test_missing_workbook_returns_structured_warning(): void
    {
        $this->fakeNakshatraLagnamWorkbook('INTRO_001', 'Aries', 'Pisces', 'Swati');
        $request = $this->requestForAgeTimeline();
        $expectedDirectory = $this->predictionDataDirectory().DIRECTORY_SEPARATOR.'past';

        $this->postJson('/api/predictions/past/prepare', [
            'prediction_request_id' => $request->id,
            'prediction_type' => 'past',
        ])
            ->assertOk()
            ->assertJsonPath('prediction_videos.0.status', 'UNAVAILABLE')
            ->assertJsonPath('prediction_videos.0.warning', "Workbook directory not found: {$expectedDirectory}");
    }

    public function test_workbook_directory_is_resolved_from_prediction_type_and_configured_base_path(): void
    {
        config(['prediction.excel_path' => $this->predictionDataDirectory()]);

        $resolver = app(\App\Services\Prediction\AgeWorkbookResolver::class);

        $this->assertSame(
            $this->predictionDataDirectory().DIRECTORY_SEPARATOR.'past',
            $resolver->resolveExcelDirectory('past')
        );
        $this->assertSame(
            $this->predictionDataDirectory().DIRECTORY_SEPARATOR.'future',
            $resolver->resolveExcelDirectory('future')
        );
    }

    public function test_missing_lagnam_rasi_position_or_year_mapping_returns_structured_warning(): void
    {
        config(['prediction.debug_response' => true]);
        $this->fakeNakshatraLagnamWorkbook('INTRO_001', 'Aries', 'Pisces', 'Swati');
        $this->createAgeWorkbook('past', '51 TO 60.xlsx', 'VENUS', $this->hierarchyRows('2014', ['PRED_2014']));

        $missingLagnam = $this->requestForAgeTimeline([
            'normalized_astrology_data' => $this->normalizedData(null, 'Pisces', 'Swati', 'Venus', 6),
        ]);
        $missingRasi = $this->requestForAgeTimeline([
            'normalized_astrology_data' => $this->normalizedData('Aries', null, 'Swati', 'Venus', 6),
        ]);
        $missingPosition = $this->requestForAgeTimeline([
            'normalized_astrology_data' => $this->normalizedData('Aries', 'Pisces', 'Swati', 'Venus', null),
        ]);

        $this->postJson('/api/predictions/past/prepare?debug=true', [
            'prediction_request_id' => $missingLagnam->id,
            'prediction_type' => 'past',
        ])->assertJsonPath('prediction_videos.0.warning', 'Lagnam is unavailable.');

        $this->postJson('/api/predictions/past/prepare?debug=true', [
            'prediction_request_id' => $missingRasi->id,
            'prediction_type' => 'past',
        ])->assertJsonPath('prediction_videos.0.warning', 'Rasi is unavailable.');

        $this->postJson('/api/predictions/past/prepare?debug=true', [
            'prediction_request_id' => $missingPosition->id,
            'prediction_type' => 'past',
        ])->assertJsonPath('prediction_videos.0.warning', 'Planet house position is unavailable.');

        $this->createAgeWorkbook('past', '51 TO 60.xlsx', 'VENUS', $this->hierarchyRows('2015', ['PRED_2015']));
        $missingYear = $this->requestForAgeTimeline();

        $this->postJson('/api/predictions/past/prepare?debug=true', [
            'prediction_request_id' => $missingYear->id,
            'prediction_type' => 'past',
        ])->assertJsonPath('prediction_videos.0.warning', 'Excel sequence year column not found.');
    }

    private function requestForAgeTimeline(array $overrides = []): PredictionRequest
    {
        return $this->createPredictionRequest(array_merge([
            'date_of_birth' => '1973-12-16',
            'completed_at' => CarbonImmutable::parse('2026-07-25 10:00:00'),
            'submitted_at' => CarbonImmutable::parse('2026-07-25 09:00:00'),
            'normalized_astrology_data' => $this->normalizedData('Aries', 'Pisces', 'Swati', 'Venus', 6),
        ], $overrides));
    }

    private function requestForBirthDate(string $dateOfBirth): PredictionRequest
    {
        return $this->requestForAgeTimeline([
            'date_of_birth' => $dateOfBirth,
            'birth_payload' => ['day' => (int) substr($dateOfBirth, 8, 2), 'month' => (int) substr($dateOfBirth, 5, 2), 'year' => (int) substr($dateOfBirth, 0, 4)],
            'normalized_astrology_data' => $this->normalizedData('Aries', 'Pisces', 'Swati', 'Venus', 6, birthDate: $dateOfBirth),
        ]);
    }

    private function createPredictionRequest(array $overrides = []): PredictionRequest
    {
        return PredictionRequest::query()->create(array_merge([
            'request_uuid' => (string) Str::uuid(),
            'prediction_type' => 'past',
            'status' => 'completed',
            'full_name' => 'Ravi Kumar',
            'date_of_birth' => '1990-04-12',
            'time_of_birth' => '06:45',
            'timezone' => 'Asia/Kolkata',
            'place_of_birth' => 'Chennai, Tamil Nadu',
            'latitude' => 13.0827,
            'longitude' => 80.2707,
            'birth_payload' => [
                'day' => 12,
                'month' => 4,
                'year' => 1990,
                'hour' => 6,
                'min' => 45,
                'lat' => 13.0827,
                'lon' => 80.2707,
                'tzone' => 5.5,
            ],
            'raw_astrology_response' => ['astro_details' => ['ascendant' => 'Mesha']],
            'normalized_astrology_data' => [
                'birth' => [
                    'date_of_birth' => '1990-04-12',
                    'time_of_birth' => '06:45',
                    'place_of_birth' => 'Chennai, Tamil Nadu',
                    'latitude' => 13.0827,
                    'longitude' => 80.2707,
                ],
                'identity' => [
                    'lagnam' => 'Aquarius',
                    'rasi' => 'Libra',
                    'nakshatra' => 'Swati',
                ],
            ],
            'submitted_at' => now(),
            'completed_at' => now(),
        ], $overrides));
    }

    private function normalizedData(
        ?string $lagnam,
        ?string $rasi,
        ?string $nakshatra,
        ?string $currentMahadasha,
        ?int $house,
        ?array $majorDasha = null,
        ?string $planetForHouse = null,
        string $birthDate = '1973-12-16'
    ): array {
        $planetForHouse ??= $currentMahadasha;
        $majorDasha ??= [
            ['planet' => 'Venus', 'planet_id' => 5, 'start' => '8-7-2019 18:21', 'end' => '8-7-2039 18:21'],
        ];
        $planets = [];

        if ($planetForHouse !== null && $house !== null) {
            $planets[] = [
                'id' => app(\App\Services\Prediction\AstrologyNameMapper::class)->planetId($planetForHouse),
                'name' => $planetForHouse,
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
                'major' => $majorDasha,
            ],
            'planets' => $planets,
        ];
    }

    private function fakeNakshatraLagnamWorkbook(
        string $videoId,
        string $lagnam = 'Aquarius',
        string $rasi = 'Libra',
        string $nakshatra = 'Swati'
    ): void {
        $this->fakeNakshatraLagnamWorkbookWithRows([
            ['Nakshatra', 'Rasi', 'Lagnam', 'VideoId'],
            [$nakshatra, $rasi, $lagnam, $videoId],
        ]);
    }

    private function fakeNakshatraLagnamWorkbookWithRows(array $rows): void
    {
        $directory = $this->predictionDataDirectory();

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        config([
            'prediction.excel_path' => $directory,
            'prediction.files.nakshatra_lagnam' => 'nakshatra_lagnam.xlsx',
        ]);

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

        config(['prediction.excel_path' => $this->predictionDataDirectory()]);

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
        return storage_path('framework/testing/prediction-data');
    }
}
