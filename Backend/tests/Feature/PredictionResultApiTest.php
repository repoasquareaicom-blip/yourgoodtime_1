<?php

namespace Tests\Feature;

use App\Models\PredictionRequest;
use App\Models\AdminUser;
use App\Models\PredictionResult;
use App\Models\PaymentTransaction;
use App\Models\PredictionEntitlement;
use App\Models\PredictionProduct;
use App\Models\PredictionRegenerationSetting;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class PredictionResultApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        File::deleteDirectory($this->predictionDataDirectory());

        config([
            'prediction.past_years' => 1,
            'prediction.future_years' => 1,
            'prediction.excel_path' => $this->predictionDataDirectory(),
        ]);
    }

    public function test_past_result_for_id_14_is_returned(): void
    {
        $this->seedSuccessfulPredictionData();
        $predictionRequest = $this->predictionRequest14();
        $this->activeFutureEntitlement($predictionRequest);

        $this->postJson('/api/predictions/result', [
            'id' => 14,
            'type' => 'past',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Prediction result generated successfully.')
            ->assertJsonPath('data.prediction_request_id', 14)
            ->assertJsonPath('data.type', 'past')
            ->assertJsonPath('data.prediction_videos.0.video_ids.0', '6153');
    }

    public function test_future_result_for_id_14_is_returned(): void
    {
        $this->seedSuccessfulPredictionData();
        $predictionRequest = $this->predictionRequest14();
        $this->activeFutureEntitlement($predictionRequest);

        $this->postJson('/api/predictions/result', [
            'id' => 14,
            'type' => 'future',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.prediction_request_id', 14)
            ->assertJsonPath('data.type', 'future')
            ->assertJsonPath('data.prediction_videos.0.video_ids.0', '619');
    }

    public function test_missing_id_returns_validation_error(): void
    {
        $this->postJson('/api/predictions/result', [
            'type' => 'past',
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Validation failed.')
            ->assertJsonValidationErrors(['id']);
    }

    public function test_invalid_type_returns_validation_error(): void
    {
        $this->postJson('/api/predictions/result', [
            'id' => 14,
            'type' => 'monthly',
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Validation failed.')
            ->assertJsonValidationErrors(['type']);
    }

    public function test_non_existing_prediction_request_returns_not_found(): void
    {
        $this->postJson('/api/predictions/result', [
            'id' => 999,
            'type' => 'past',
        ])
            ->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Prediction request not found.');
    }

    public function test_response_contains_identity_video(): void
    {
        $this->seedSuccessfulPredictionData();
        $this->predictionRequest14();

        $this->postJson('/api/predictions/result', [
            'id' => 14,
            'type' => 'past',
        ])
            ->assertOk()
            ->assertJsonPath('data.identity_video.video_id', '34')
            ->assertJsonPath('data.identity_video.source.file', 'nakshatra_lagnam.xlsx');
    }

    public function test_response_contains_prediction_videos(): void
    {
        $this->seedSuccessfulPredictionData();
        $this->predictionRequest14();

        $this->postJson('/api/predictions/result', [
            'id' => 14,
            'type' => 'past',
        ])
            ->assertOk()
            ->assertJsonPath('data.prediction_videos.0.age', 52)
            ->assertJsonPath('data.prediction_videos.0.system_year', 2026)
            ->assertJsonPath('data.prediction_videos.0.excel_sequence_year', 2014)
            ->assertJsonMissingPath('data.prediction_videos.0.lookup_year')
            ->assertJsonMissingPath('data.prediction_videos.0.calendar_year')
            ->assertJsonPath('data.prediction_videos.0.status', 'SUCCESS');
    }

    public function test_past_first_age_is_current_completed_age(): void
    {
        $this->seedSuccessfulPredictionData();
        $this->predictionRequest14();

        $this->postJson('/api/predictions/result', [
            'id' => 14,
            'type' => 'past',
        ])
            ->assertOk()
            ->assertJsonPath('data.prediction_videos.0.age', 52);
    }

    public function test_future_first_age_is_current_age_plus_one(): void
    {
        $this->seedSuccessfulPredictionData();
        $predictionRequest = $this->predictionRequest14();
        $this->activeFutureEntitlement($predictionRequest);

        $this->postJson('/api/predictions/result', [
            'id' => 14,
            'type' => 'future',
        ])
            ->assertOk()
            ->assertJsonPath('data.prediction_videos.0.age', 53)
            ->assertJsonPath('data.prediction_videos.0.system_year', 2027);
    }

    public function test_future_result_cannot_be_generated_without_active_entitlement(): void
    {
        $this->seedSuccessfulPredictionData();
        $this->predictionRequest14();

        $this->postJson('/api/predictions/result', [
            'id' => 14,
            'type' => 'future',
        ])
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_structured_warning_is_preserved_when_mapping_is_unavailable(): void
    {
        $this->fakeNakshatraLagnamWorkbook();
        $this->createAgeWorkbook('past', '51 TO 60.xlsx', 'VENUS', $this->hierarchyRows('2015', ['WRONG_YEAR']));
        $this->predictionRequest14();

        $this->postJson('/api/predictions/result', [
            'id' => 14,
            'type' => 'past',
        ])
            ->assertOk()
            ->assertJsonPath('data.prediction_videos.0.status', 'UNAVAILABLE')
            ->assertJsonPath('data.prediction_videos.0.warning', 'Excel sequence year column not found.')
            ->assertJsonPath('data.warnings.0.warning', 'Excel sequence year column not found.');
    }

    public function test_verified_request_generates_and_persists_result(): void
    {
        $this->seedSuccessfulPredictionData();
        $predictionRequest = $this->predictionRequest14();

        $this->postJson('/api/predictions/result', [
            'id' => $predictionRequest->id,
            'type' => 'past',
            'request_uuid' => $predictionRequest->request_uuid,
        ])
            ->assertOk()
            ->assertJsonPath('data.identity_video.video_id', '34');

        $this->assertDatabaseHas('prediction_results', [
            'prediction_request_id' => $predictionRequest->id,
            'prediction_type' => 'past',
            'identity_video_id' => '34',
            'status' => 'completed',
        ]);
    }

    public function test_repeated_result_request_does_not_create_duplicate_rows(): void
    {
        $this->seedSuccessfulPredictionData();
        $predictionRequest = $this->predictionRequest14();

        $payload = [
            'id' => $predictionRequest->id,
            'type' => 'past',
            'request_uuid' => $predictionRequest->request_uuid,
        ];

        $this->postJson('/api/predictions/result', $payload)->assertOk();
        $this->postJson('/api/predictions/result', $payload)->assertOk();

        $this->assertSame(1, PredictionResult::query()
            ->where('prediction_request_id', $predictionRequest->id)
            ->where('prediction_type', 'past')
            ->count());
    }

    public function test_stored_result_response_recovers_identity_video_from_column_when_payload_is_missing_it(): void
    {
        $predictionRequest = $this->predictionRequest14();
        $storedResult = PredictionResult::query()->create([
            'prediction_request_id' => $predictionRequest->id,
            'prediction_type' => 'past',
            'identity_video_id' => '34',
            'result_payload' => [
                'prediction_request_id' => $predictionRequest->id,
                'type' => 'past',
                'prediction_videos' => [
                    [
                        'video_ids' => ['443'],
                        'system_year_from' => 2016,
                        'workbook' => '16.xlsx',
                    ],
                ],
                'summary' => [
                    'to_year' => now(config('services.prediction_billing.business_timezone', 'Asia/Kolkata'))->year,
                ],
                'warnings' => [],
            ],
            'summary_payload' => [],
            'warnings_payload' => [],
            'status' => 'completed',
            'generated_at' => now(),
        ]);

        $this->postJson('/api/predictions/result', [
            'id' => $predictionRequest->id,
            'type' => 'past',
            'request_uuid' => $predictionRequest->request_uuid,
        ])
            ->assertOk()
            ->assertJsonPath('data.result_id', $storedResult->id)
            ->assertJsonPath('data.identity_video.video_id', '34')
            ->assertJsonPath('data.identity_video.source.file', 'nakshatra_lagnam.xlsx')
            ->assertJsonPath('data.prediction_videos.0.video_ids.0', '443');
    }

    public function test_old_result_recovers_missing_identity_video_from_stored_astrology(): void
    {
        $this->fakeNakshatraLagnamWorkbook();
        $predictionRequest = $this->predictionRequest14();
        $predictionRequest->forceFill([
            'normalized_astrology_data' => $this->productionShapedNormalizedData(),
        ])->save();
        $predictionVideos = [
            [
                'video_ids' => ['443'],
                'system_year' => 2026,
                'workbook' => '51 TO 60.xlsx',
            ],
        ];
        $storedResult = PredictionResult::query()->create([
            'prediction_request_id' => $predictionRequest->id,
            'prediction_type' => 'past',
            'identity_video_id' => null,
            'result_payload' => [
                'prediction_request_id' => $predictionRequest->id,
                'type' => 'past',
                'identity_video' => null,
                'prediction_videos' => $predictionVideos,
                'summary' => [],
                'warnings' => [],
            ],
            'summary_payload' => [],
            'warnings_payload' => [],
            'status' => 'completed',
            'generated_at' => now(),
        ]);

        $this->getJson("/api/predictions/{$predictionRequest->id}/result?type=past&request_uuid={$predictionRequest->request_uuid}")
            ->assertOk()
            ->assertJsonPath('data.result_id', $storedResult->id)
            ->assertJsonPath('data.identity_video.video_id', '34')
            ->assertJsonPath('data.identity_video.source.file', 'nakshatra_lagnam.xlsx')
            ->assertJsonPath('data.identity_video.source.sheet', 'Rule001')
            ->assertJsonPath('data.identity_video.source.nakshatra', 'Poorvabhadrapada')
            ->assertJsonPath('data.prediction_videos.0.video_ids.0', '443');

        $storedResult->refresh();
        $this->assertSame('34', $storedResult->identity_video_id);
        $this->assertSame('34', data_get($storedResult->result_payload, 'identity_video.video_id'));
        $this->assertSame($predictionVideos, data_get($storedResult->result_payload, 'prediction_videos'));
    }

    public function test_payload_identity_is_restored_from_identity_video_column_without_rule001(): void
    {
        $predictionRequest = $this->predictionRequest14();
        $storedResult = PredictionResult::query()->create([
            'prediction_request_id' => $predictionRequest->id,
            'prediction_type' => 'past',
            'identity_video_id' => '34',
            'result_payload' => [
                'prediction_request_id' => $predictionRequest->id,
                'type' => 'past',
                'prediction_videos' => [['video_ids' => ['443']]],
                'summary' => [],
                'warnings' => [],
            ],
            'summary_payload' => [],
            'warnings_payload' => [],
            'status' => 'completed',
            'generated_at' => now(),
        ]);

        File::deleteDirectory($this->predictionDataDirectory());

        $this->getJson("/api/predictions/{$predictionRequest->id}/result?type=past&request_uuid={$predictionRequest->request_uuid}")
            ->assertOk()
            ->assertJsonPath('data.result_id', $storedResult->id)
            ->assertJsonPath('data.identity_video.video_id', '34')
            ->assertJsonPath('data.identity_video.source.file', 'nakshatra_lagnam.xlsx');

        $this->assertSame('34', data_get($storedResult->refresh()->result_payload, 'identity_video.video_id'));
    }

    public function test_existing_payload_identity_is_preserved_unchanged(): void
    {
        $predictionRequest = $this->predictionRequest14();
        $identity = [
            'video_id' => 'INTRO_CUSTOM',
            'source' => [
                'file' => 'custom.xlsx',
                'sheet' => 'Original',
                'status' => 'LEGACY',
            ],
        ];
        $storedResult = PredictionResult::query()->create([
            'prediction_request_id' => $predictionRequest->id,
            'prediction_type' => 'past',
            'identity_video_id' => null,
            'result_payload' => [
                'prediction_request_id' => $predictionRequest->id,
                'type' => 'past',
                'identity_video' => $identity,
                'prediction_videos' => [['video_ids' => ['443']]],
                'summary' => [],
                'warnings' => [],
            ],
            'summary_payload' => [],
            'warnings_payload' => [],
            'status' => 'completed',
            'generated_at' => now(),
        ]);

        File::deleteDirectory($this->predictionDataDirectory());

        $this->getJson("/api/predictions/{$predictionRequest->id}/result?type=past&request_uuid={$predictionRequest->request_uuid}")
            ->assertOk()
            ->assertJsonPath('data.identity_video.video_id', 'INTRO_CUSTOM')
            ->assertJsonPath('data.identity_video.source.file', 'custom.xlsx');

        $storedResult->refresh();
        $this->assertSame('INTRO_CUSTOM', $storedResult->identity_video_id);
        $this->assertSame($identity, data_get($storedResult->result_payload, 'identity_video'));
    }

    public function test_future_result_recovers_identity_without_changing_prediction_videos(): void
    {
        $this->fakeNakshatraLagnamWorkbook();
        $predictionRequest = $this->predictionRequest14();
        $entitlement = $this->activeFutureEntitlement($predictionRequest);
        $predictionVideos = [
            [
                'system_year' => $entitlement->access_start_year,
                'video_ids' => ['619'],
                'workbook' => 'FUTURE_51 TO 60.xlsx',
            ],
        ];
        $storedResult = PredictionResult::query()->create([
            'prediction_request_id' => $predictionRequest->id,
            'user_id' => $entitlement->user_id,
            'prediction_entitlement_id' => $entitlement->id,
            'prediction_type' => 'future',
            'identity_video_id' => null,
            'result_payload' => [
                'prediction_request_id' => $predictionRequest->id,
                'type' => 'future',
                'prediction_videos' => $predictionVideos,
                'summary' => [],
                'warnings' => [],
            ],
            'summary_payload' => [],
            'warnings_payload' => [],
            'status' => 'completed',
            'generated_at' => now(),
        ]);

        $this->getJson("/api/predictions/{$predictionRequest->id}/result?type=future&request_uuid={$predictionRequest->request_uuid}&entitlement_id={$entitlement->id}")
            ->assertOk()
            ->assertJsonPath('data.result_id', $storedResult->id)
            ->assertJsonPath('data.identity_video.video_id', '34')
            ->assertJsonPath('data.prediction_videos.0.video_ids.0', '619');

        $this->assertSame($predictionVideos, data_get($storedResult->refresh()->result_payload, 'prediction_videos'));
    }

    public function test_reopening_recovered_result_does_not_rerun_rule001(): void
    {
        $this->fakeNakshatraLagnamWorkbook();
        $predictionRequest = $this->predictionRequest14();
        PredictionResult::query()->create([
            'prediction_request_id' => $predictionRequest->id,
            'prediction_type' => 'past',
            'identity_video_id' => null,
            'result_payload' => [
                'prediction_request_id' => $predictionRequest->id,
                'type' => 'past',
                'prediction_videos' => [['video_ids' => ['443']]],
                'summary' => [],
                'warnings' => [],
            ],
            'summary_payload' => [],
            'warnings_payload' => [],
            'status' => 'completed',
            'generated_at' => now(),
        ]);

        $url = "/api/predictions/{$predictionRequest->id}/result?type=past&request_uuid={$predictionRequest->request_uuid}";
        $this->getJson($url)->assertOk()->assertJsonPath('data.identity_video.video_id', '34');

        File::deleteDirectory($this->predictionDataDirectory());

        $this->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.identity_video.video_id', '34');
    }

    public function test_missing_stored_astrology_does_not_invent_identity_video(): void
    {
        $this->fakeNakshatraLagnamWorkbook();
        $predictionRequest = $this->predictionRequest14();
        $predictionRequest->forceFill([
            'normalized_astrology_data' => ['identity' => ['lagnam' => null, 'rasi' => null, 'nakshatra' => null]],
        ])->save();
        $storedResult = PredictionResult::query()->create([
            'prediction_request_id' => $predictionRequest->id,
            'prediction_type' => 'past',
            'identity_video_id' => null,
            'result_payload' => [
                'prediction_request_id' => $predictionRequest->id,
                'type' => 'past',
                'prediction_videos' => [['video_ids' => ['443']]],
                'summary' => [],
                'warnings' => [],
            ],
            'summary_payload' => [],
            'warnings_payload' => [],
            'status' => 'completed',
            'generated_at' => now(),
        ]);

        $this->getJson("/api/predictions/{$predictionRequest->id}/result?type=past&request_uuid={$predictionRequest->request_uuid}")
            ->assertOk()
            ->assertJsonPath('data.result_id', $storedResult->id)
            ->assertJsonPath('data.identity_video', null);

        $storedResult->refresh();
        $this->assertNull($storedResult->identity_video_id);
        $this->assertNull(data_get($storedResult->result_payload, 'identity_video'));
    }

    public function test_stale_past_result_is_regenerated_when_current_past_range_moves_forward(): void
    {
        $this->seedSuccessfulPredictionData();
        $predictionRequest = $this->predictionRequest14();
        $currentYear = (int) now(config('services.prediction_billing.business_timezone', 'Asia/Kolkata'))->format('Y');

        PredictionResult::query()->create([
            'prediction_request_id' => $predictionRequest->id,
            'prediction_type' => 'past',
            'identity_video_id' => 'old-identity',
            'result_payload' => [
                'prediction_request_id' => $predictionRequest->id,
                'type' => 'past',
                'identity_video' => ['video_id' => 'old-identity'],
                'prediction_videos' => [],
                'summary' => ['from_year' => $currentYear - 2, 'to_year' => $currentYear - 1],
                'warnings' => [],
            ],
            'summary_payload' => ['from_year' => $currentYear - 2, 'to_year' => $currentYear - 1],
            'warnings_payload' => [],
            'status' => 'completed',
            'generated_at' => now()->subYear(),
        ]);

        $this->postJson('/api/predictions/result', [
            'id' => $predictionRequest->id,
            'type' => 'past',
            'request_uuid' => $predictionRequest->request_uuid,
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Prediction result generated successfully.')
            ->assertJsonPath('data.identity_video.video_id', 'old-identity')
            ->assertJsonPath('data.summary.to_year', $currentYear);

        $this->assertDatabaseHas('prediction_results', [
            'prediction_request_id' => $predictionRequest->id,
            'prediction_type' => 'past',
            'identity_video_id' => 'old-identity',
        ]);
    }

    public function test_existing_result_is_returned_without_regenerating_engine(): void
    {
        $predictionRequest = $this->predictionRequest14();
        PredictionResult::query()->create([
            'prediction_request_id' => $predictionRequest->id,
            'prediction_type' => 'past',
            'identity_video_id' => 'stored-identity',
            'result_payload' => [
                'prediction_request_id' => $predictionRequest->id,
                'type' => 'past',
                'identity_video' => ['video_id' => 'stored-identity'],
                'prediction_videos' => [],
                'summary' => ['grouped_result_count' => 0],
                'warnings' => [],
            ],
            'summary_payload' => ['grouped_result_count' => 0],
            'warnings_payload' => [],
            'status' => 'completed',
            'generated_at' => now(),
        ]);

        $this->getJson(sprintf(
            '/api/predictions/%d/result?type=past&request_uuid=%s',
            $predictionRequest->id,
            $predictionRequest->request_uuid
        ))
            ->assertOk()
            ->assertJsonPath('message', 'Prediction result loaded successfully.')
            ->assertJsonPath('data.identity_video.video_id', 'stored-identity');
    }

    public function test_global_past_regeneration_version_regenerates_once_then_returns_stored_payload(): void
    {
        $this->seedSuccessfulPredictionData();
        $predictionRequest = $this->predictionRequest14();
        $currentYear = (int) now(config('services.prediction_billing.business_timezone', 'Asia/Kolkata'))->format('Y');
        $storedResult = $this->storedPastResult($predictionRequest, [
            'identity_video' => ['video_id' => 'old-identity'],
            'prediction_videos' => [['system_year' => $currentYear, 'video_ids' => ['OLD']]],
            'summary' => ['to_year' => $currentYear],
        ], ['regeneration_version' => 1]);
        $this->setRegenerationSetting('past', 2, true);

        $this->postJson('/api/predictions/result', [
            'id' => $predictionRequest->id,
            'type' => 'past',
            'request_uuid' => $predictionRequest->request_uuid,
        ])
            ->assertOk()
            ->assertJsonPath('data.identity_video.video_id', 'old-identity')
            ->assertJsonPath('data.prediction_videos.0.video_ids.0', '6153');

        $storedResult->refresh();
        $this->assertSame(2, (int) $storedResult->regeneration_version);

        $this->createAgeWorkbook('past', '51 TO 60.xlsx', 'VENUS', $this->hierarchyRows('2014', ['9999']));

        $this->postJson('/api/predictions/result', [
            'id' => $predictionRequest->id,
            'type' => 'past',
            'request_uuid' => $predictionRequest->request_uuid,
        ])
            ->assertOk()
            ->assertJsonPath('data.prediction_videos.0.video_ids.0', '6153');

        $this->setRegenerationSetting('past', 3, true);

        $this->postJson('/api/predictions/result', [
            'id' => $predictionRequest->id,
            'type' => 'past',
            'request_uuid' => $predictionRequest->request_uuid,
        ])
            ->assertOk()
            ->assertJsonPath('data.identity_video.video_id', 'old-identity')
            ->assertJsonPath('data.prediction_videos.0.video_ids.0', '9999');

        $this->assertSame(3, (int) $storedResult->fresh()->regeneration_version);
    }

    public function test_future_regeneration_requires_active_entitlement_and_is_independent_from_past(): void
    {
        $this->seedSuccessfulPredictionData();
        $predictionRequest = $this->predictionRequest14();
        $entitlement = $this->activeFutureEntitlement($predictionRequest);
        $futureResult = $this->storedFutureResult($predictionRequest, $entitlement, ['OLD_FUTURE'], 1);
        $this->setRegenerationSetting('past', 2, true);

        $this->postJson('/api/predictions/result', [
            'id' => $predictionRequest->id,
            'type' => 'future',
            'request_uuid' => $predictionRequest->request_uuid,
            'entitlement_id' => $entitlement->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.prediction_videos.0.video_ids.0', 'OLD_FUTURE');

        $this->setRegenerationSetting('future', 2, true);

        $unauthorizedFutureRequest = PredictionRequest::query()->forceCreate([
            'id' => 15,
            'request_uuid' => (string) Str::uuid(),
            'prediction_type' => 'past',
            'status' => 'completed',
            'full_name' => 'No Entitlement',
            'date_of_birth' => '1973-12-16',
            'time_of_birth' => '06:45',
            'timezone' => 'Asia/Kolkata',
            'place_of_birth' => 'Chennai, Tamil Nadu',
            'email' => 'no-entitlement@example.com',
            'latitude' => 13.0827,
            'longitude' => 80.2707,
            'birth_payload' => [],
            'normalized_astrology_data' => $this->normalizedData(),
            'submitted_at' => now(),
            'completed_at' => now(),
        ]);

        $this->postJson('/api/predictions/result', [
            'id' => $unauthorizedFutureRequest->id,
            'type' => 'future',
            'request_uuid' => $unauthorizedFutureRequest->request_uuid,
        ])->assertForbidden();

        $this->postJson('/api/predictions/result', [
            'id' => $predictionRequest->id,
            'type' => 'future',
            'request_uuid' => $predictionRequest->request_uuid,
            'entitlement_id' => $entitlement->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.identity_video.video_id', 'future-identity')
            ->assertJsonPath('data.prediction_videos.0.video_ids.0', '619');

        $futureResult->refresh();
        $this->assertSame(2, (int) $futureResult->regeneration_version);
        $this->assertSame($entitlement->id, (int) $futureResult->prediction_entitlement_id);
        $this->assertSame('future-identity', data_get($futureResult->result_payload, 'identity_video.video_id'));
    }

    public function test_regeneration_failure_keeps_previous_payload_and_version(): void
    {
        $predictionRequest = $this->predictionRequest14();
        $currentYear = (int) now(config('services.prediction_billing.business_timezone', 'Asia/Kolkata'))->format('Y');
        $storedResult = $this->storedPastResult($predictionRequest, [
            'identity_video' => ['video_id' => 'old-identity'],
            'prediction_videos' => [['system_year' => $currentYear, 'video_ids' => ['OLD']]],
            'summary' => ['to_year' => $currentYear],
        ], ['regeneration_version' => 1]);
        $this->setRegenerationSetting('past', 2, true);

        $this->postJson('/api/predictions/result', [
            'id' => $predictionRequest->id,
            'type' => 'past',
            'request_uuid' => $predictionRequest->request_uuid,
        ])->assertStatus(500);

        $storedResult->refresh();
        $this->assertSame(1, (int) $storedResult->regeneration_version);
        $this->assertSame('OLD', data_get($storedResult->result_payload, 'prediction_videos.0.video_ids.0'));
        $this->assertSame('completed', $storedResult->status);
    }

    public function test_regeneration_restores_identity_video_payload_from_existing_identity_column(): void
    {
        $this->seedSuccessfulPredictionData();
        $predictionRequest = $this->predictionRequest14();
        $currentYear = (int) now(config('services.prediction_billing.business_timezone', 'Asia/Kolkata'))->format('Y');
        $storedResult = PredictionResult::query()->create([
            'prediction_request_id' => $predictionRequest->id,
            'prediction_type' => 'past',
            'identity_video_id' => '00034',
            'result_payload' => [
                'prediction_request_id' => $predictionRequest->id,
                'type' => 'past',
                'prediction_videos' => [['system_year' => $currentYear, 'video_ids' => ['OLD']]],
                'summary' => ['to_year' => $currentYear],
                'warnings' => [],
            ],
            'summary_payload' => ['to_year' => $currentYear],
            'warnings_payload' => [],
            'status' => 'completed',
            'regeneration_version' => 1,
            'generated_at' => now()->subDay(),
        ]);
        $this->setRegenerationSetting('past', 2, true);

        $this->postJson('/api/predictions/result', [
            'id' => $predictionRequest->id,
            'type' => 'past',
            'request_uuid' => $predictionRequest->request_uuid,
        ])
            ->assertOk()
            ->assertJsonPath('data.identity_video.video_id', '00034')
            ->assertJsonPath('data.prediction_videos.0.video_ids.0', '6153');

        $storedResult->refresh();
        $this->assertSame('00034', $storedResult->identity_video_id);
        $this->assertSame('00034', data_get($storedResult->result_payload, 'identity_video.video_id'));
        $this->assertSame('nakshatra_lagnam.xlsx', data_get($storedResult->result_payload, 'identity_video.source.file'));
    }

    public function test_admin_individual_regenerate_past_sets_current_global_version(): void
    {
        $this->seedSuccessfulPredictionData();
        $admin = AdminUser::query()->create([
            'name' => 'Admin',
            'email' => 'admin-regenerate@example.com',
            'password' => Hash::make('secret123'),
            'is_active' => true,
        ]);
        $predictionRequest = $this->predictionRequest14();
        $this->storedPastResult($predictionRequest, [
            'identity_video' => ['video_id' => 'old-identity'],
            'prediction_videos' => [['system_year' => 2026, 'video_ids' => ['OLD']]],
            'summary' => ['to_year' => 2026],
        ], ['regeneration_version' => 1]);
        $this->setRegenerationSetting('past', 4, true);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/prediction-requests/{$predictionRequest->id}/regenerate-past")
            ->assertOk()
            ->assertJsonPath('data.result.regeneration_version', 4);

        $this->assertDatabaseHas('prediction_results', [
            'prediction_request_id' => $predictionRequest->id,
            'prediction_type' => 'past',
            'regeneration_version' => 4,
        ]);
    }

    public function test_admin_can_increment_global_regeneration_version_without_touching_results(): void
    {
        $admin = AdminUser::query()->create([
            'name' => 'Admin',
            'email' => 'admin-mark-regeneration@example.com',
            'password' => Hash::make('secret123'),
            'is_active' => true,
        ]);
        $predictionRequest = $this->predictionRequest14();
        $result = $this->storedPastResult($predictionRequest, [], ['regeneration_version' => 1]);
        $this->setRegenerationSetting('past', 2, true);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/prediction-regeneration-settings/past/mark')
            ->assertOk()
            ->assertJsonPath('data.prediction_type', 'past')
            ->assertJsonPath('data.regeneration_version', 3)
            ->assertJsonPath('data.is_enabled', true);

        $this->assertSame(1, (int) $result->fresh()->regeneration_version);
    }

    public function test_future_stored_result_can_be_loaded_for_selected_entitlement_only(): void
    {
        $predictionRequest = $this->predictionRequest14();
        $firstEntitlement = $this->activeFutureEntitlement($predictionRequest);
        $secondEntitlement = $this->activeFutureEntitlementForYears($predictionRequest, 2032, 2036);

        foreach ([[$firstEntitlement, 'FIRST_FUTURE'], [$secondEntitlement, 'SECOND_FUTURE']] as [$entitlement, $videoId]) {
            PredictionResult::query()->create([
                'prediction_request_id' => $predictionRequest->id,
                'prediction_entitlement_id' => $entitlement->id,
                'prediction_type' => 'future',
                'identity_video_id' => 'future-identity',
                'result_payload' => [
                    'prediction_request_id' => $predictionRequest->id,
                    'type' => 'future',
                    'identity_video' => ['video_id' => 'future-identity'],
                    'prediction_videos' => [
                        ['system_year' => $entitlement->access_start_year, 'video_ids' => [$videoId]],
                    ],
                    'summary' => [],
                    'warnings' => [],
                ],
                'summary_payload' => [],
                'warnings_payload' => [],
                'status' => 'completed',
                'generated_at' => now(),
            ]);
        }

        $this->getJson(sprintf(
            '/api/predictions/%d/result?type=future&request_uuid=%s&entitlement_id=%d',
            $predictionRequest->id,
            $predictionRequest->request_uuid,
            $secondEntitlement->id
        ))
            ->assertOk()
            ->assertJsonPath('data.prediction_videos.0.video_ids.0', 'SECOND_FUTURE')
            ->assertJsonMissingPath('data.result_ids');
    }

    public function test_another_request_cannot_retrieve_stored_result(): void
    {
        $predictionRequest = $this->predictionRequest14();
        PredictionResult::query()->create([
            'prediction_request_id' => $predictionRequest->id,
            'prediction_type' => 'past',
            'identity_video_id' => '34',
            'result_payload' => [
                'prediction_request_id' => $predictionRequest->id,
                'type' => 'past',
                'identity_video' => ['video_id' => '34'],
                'prediction_videos' => [],
                'summary' => [],
                'warnings' => [],
            ],
            'summary_payload' => [],
            'warnings_payload' => [],
            'status' => 'completed',
            'generated_at' => now(),
        ]);

        $this->getJson(sprintf(
            '/api/predictions/%d/result?type=past&request_uuid=%s',
            $predictionRequest->id,
            (string) Str::uuid()
        ))
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_session_status_rejects_invalid_request_uuid(): void
    {
        $predictionRequest = $this->predictionRequest14();

        $this->getJson(sprintf(
            '/api/predictions/session-status?prediction_request_id=%d&prediction_type=past&request_uuid=%s',
            $predictionRequest->id,
            (string) Str::uuid()
        ))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.valid', false);
    }

    public function test_session_status_returns_safe_verified_request_status(): void
    {
        $predictionRequest = $this->predictionRequest14();

        $this->getJson(sprintf(
            '/api/predictions/session-status?prediction_request_id=%d&prediction_type=past&request_uuid=%s',
            $predictionRequest->id,
            $predictionRequest->request_uuid
        ))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.valid', true)
            ->assertJsonPath('data.prediction_request_id', $predictionRequest->id)
            ->assertJsonPath('data.prediction_type', 'past')
            ->assertJsonPath('data.otp_verified', true)
            ->assertJsonPath('data.result_ready', false)
            ->assertJsonPath('data.name', 'Ravi Kumar')
            ->assertJsonPath('data.masked_email', 'r***@example.com')
            ->assertJsonMissingPath('data.raw_astrology_response')
            ->assertJsonMissingPath('data.result_payload');
    }

    public function test_session_status_returns_result_only_for_matching_request_uuid(): void
    {
        $predictionRequest = $this->predictionRequest14();
        $storedResult = PredictionResult::query()->create([
            'prediction_request_id' => $predictionRequest->id,
            'prediction_type' => 'past',
            'identity_video_id' => '34',
            'result_payload' => [
                'prediction_request_id' => $predictionRequest->id,
                'type' => 'past',
                'identity_video' => ['video_id' => '34'],
                'prediction_videos' => [],
                'summary' => [],
                'warnings' => [],
            ],
            'summary_payload' => [],
            'warnings_payload' => [],
            'status' => 'completed',
            'generated_at' => now(),
        ]);

        $this->getJson(sprintf(
            '/api/predictions/session-status?prediction_request_id=%d&prediction_type=past&request_uuid=%s',
            $predictionRequest->id,
            $predictionRequest->request_uuid
        ))
            ->assertOk()
            ->assertJsonPath('data.valid', true)
            ->assertJsonPath('data.result_ready', true)
            ->assertJsonPath('data.result_id', $storedResult->id);

        $this->getJson(sprintf(
            '/api/predictions/session-status?prediction_request_id=%d&prediction_type=past&request_uuid=%s',
            $predictionRequest->id,
            (string) Str::uuid()
        ))
            ->assertOk()
            ->assertJsonPath('data.valid', false)
            ->assertJsonMissingPath('data.result_id');
    }

    public function test_multiple_video_ids_are_preserved_in_order(): void
    {
        $this->fakeNakshatraLagnamWorkbook();
        $this->createAgeWorkbook('past', '51 TO 60.xlsx', 'VENUS', $this->hierarchyRows('2014', ['6152', '6153', '6154']));
        $predictionRequest = $this->predictionRequest14();

        $this->postJson('/api/predictions/result', [
            'id' => $predictionRequest->id,
            'type' => 'past',
            'request_uuid' => $predictionRequest->request_uuid,
        ])
            ->assertOk()
            ->assertJsonPath('data.prediction_videos.0.video_ids.0', '6152')
            ->assertJsonPath('data.prediction_videos.0.video_ids.1', '6153')
            ->assertJsonPath('data.prediction_videos.0.video_ids.2', '6154');
    }

    private function seedSuccessfulPredictionData(): void
    {
        $this->fakeNakshatraLagnamWorkbook();
        $this->createAgeWorkbook('past', '51 TO 60.xlsx', 'VENUS', $this->hierarchyRows('2014', ['6153']));
        $this->createAgeWorkbook('future', 'FUTURE_51 TO 60.xlsx', 'VENUS', $this->hierarchyRows('2006', ['619']));
    }

    private function setRegenerationSetting(string $predictionType, int $version, bool $enabled): void
    {
        PredictionRegenerationSetting::query()->updateOrCreate(
            ['prediction_type' => $predictionType],
            ['regeneration_version' => $version, 'is_enabled' => $enabled]
        );
    }

    private function storedPastResult(PredictionRequest $predictionRequest, array $payloadOverrides = [], array $overrides = []): PredictionResult
    {
        $payload = array_replace_recursive([
            'prediction_request_id' => $predictionRequest->id,
            'type' => 'past',
            'identity_video' => ['video_id' => '34'],
            'prediction_videos' => [],
            'summary' => [],
            'warnings' => [],
        ], $payloadOverrides);

        return PredictionResult::query()->create(array_merge([
            'prediction_request_id' => $predictionRequest->id,
            'prediction_type' => 'past',
            'identity_video_id' => data_get($payload, 'identity_video.video_id'),
            'result_payload' => $payload,
            'summary_payload' => $payload['summary'] ?? [],
            'warnings_payload' => $payload['warnings'] ?? [],
            'status' => 'completed',
            'regeneration_version' => 0,
            'generated_at' => now(),
        ], $overrides));
    }

    private function storedFutureResult(PredictionRequest $predictionRequest, PredictionEntitlement $entitlement, array $videoIds, int $regenerationVersion): PredictionResult
    {
        return PredictionResult::query()->create([
            'prediction_request_id' => $predictionRequest->id,
            'prediction_entitlement_id' => $entitlement->id,
            'prediction_type' => 'future',
            'identity_video_id' => 'future-identity',
            'result_payload' => [
                'prediction_request_id' => $predictionRequest->id,
                'type' => 'future',
                'identity_video' => ['video_id' => 'future-identity'],
                'prediction_videos' => [
                    ['system_year' => $entitlement->access_start_year, 'video_ids' => $videoIds],
                ],
                'summary' => [],
                'warnings' => [],
            ],
            'summary_payload' => [],
            'warnings_payload' => [],
            'status' => 'completed',
            'regeneration_version' => $regenerationVersion,
            'generated_at' => now(),
        ]);
    }

    private function predictionRequest14(): PredictionRequest
    {
        return PredictionRequest::query()->forceCreate([
            'id' => 14,
            'request_uuid' => (string) Str::uuid(),
            'prediction_type' => 'past',
            'status' => 'completed',
            'full_name' => 'Ravi Kumar',
            'date_of_birth' => '1973-12-16',
            'time_of_birth' => '06:45',
            'timezone' => 'Asia/Kolkata',
            'place_of_birth' => 'Chennai, Tamil Nadu',
            'email' => 'ravi@example.com',
            'latitude' => 13.0827,
            'longitude' => 80.2707,
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
            'raw_astrology_response' => ['astro_details' => ['ascendant' => 'Mesha']],
            'normalized_astrology_data' => $this->normalizedData(),
            'submitted_at' => CarbonImmutable::parse('2026-07-25 09:00:00'),
            'completed_at' => CarbonImmutable::parse('2026-07-25 10:00:00'),
        ]);
    }

    private function activeFutureEntitlement(PredictionRequest $predictionRequest): PredictionEntitlement
    {
        $product = PredictionProduct::query()->create([
            'code' => 'FUTURE_PREDICTION',
            'name' => 'Future Prediction',
            'prediction_type' => 'future',
            'price_inclusive_tax_minor' => 60000,
            'currency' => 'INR',
            'tax_inclusive' => true,
            'is_active' => true,
        ]);

        $transaction = PaymentTransaction::query()->create([
            'prediction_request_id' => $predictionRequest->id,
            'product_id' => $product->id,
            'provider' => 'razorpay',
            'currency' => 'INR',
            'amount_minor' => 60000,
            'tax_inclusive' => true,
            'status' => 'CAPTURED',
            'provider_order_id' => 'order_test_future_'.$predictionRequest->id,
            'provider_payment_id' => 'pay_test_future_'.$predictionRequest->id,
            'paid_at' => now(),
            'verified_at' => now(),
        ]);

        return PredictionEntitlement::query()->create([
            'prediction_request_id' => $predictionRequest->id,
            'product_id' => $product->id,
            'payment_transaction_id' => $transaction->id,
            'prediction_type' => 'future',
            'access_start_year' => 2027,
            'access_end_year' => 2031,
            'starts_at' => now(),
            'expires_at' => now()->addYears(5),
            'status' => 'ACTIVE',
        ]);
    }

    private function activeFutureEntitlementForYears(PredictionRequest $predictionRequest, int $startYear, int $endYear): PredictionEntitlement
    {
        $product = PredictionProduct::query()->first()
            ?? PredictionProduct::query()->create([
                'code' => 'FUTURE_PREDICTION',
                'name' => 'Future Prediction',
                'prediction_type' => 'future',
                'price_inclusive_tax_minor' => 60000,
                'currency' => 'INR',
                'tax_inclusive' => true,
                'is_active' => true,
            ]);

        $transaction = PaymentTransaction::query()->create([
            'prediction_request_id' => $predictionRequest->id,
            'product_id' => $product->id,
            'provider' => 'razorpay',
            'currency' => 'INR',
            'amount_minor' => 60000,
            'tax_inclusive' => true,
            'status' => 'CAPTURED',
            'provider_order_id' => 'order_test_future_'.$predictionRequest->id.'_'.$startYear,
            'provider_payment_id' => 'pay_test_future_'.$predictionRequest->id.'_'.$startYear,
            'paid_at' => now(),
            'verified_at' => now(),
        ]);

        return PredictionEntitlement::query()->create([
            'prediction_request_id' => $predictionRequest->id,
            'product_id' => $product->id,
            'payment_transaction_id' => $transaction->id,
            'prediction_type' => 'future',
            'access_start_year' => $startYear,
            'access_end_year' => $endYear,
            'starts_at' => now(),
            'expires_at' => now()->addYears(5),
            'status' => 'ACTIVE',
        ]);
    }

    private function normalizedData(): array
    {
        return [
            'birth' => [
                'date_of_birth' => '1973-12-16',
                'time_of_birth' => '06:45',
                'place_of_birth' => 'Chennai, Tamil Nadu',
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
                    ['planet' => 'Venus', 'planet_id' => 5, 'start' => '8-7-2019 18:21', 'end' => '8-7-2039 18:21'],
                ],
            ],
            'planets' => [
                ['id' => 5, 'name' => 'Venus', 'house' => 6],
            ],
        ];
    }

    private function productionShapedNormalizedData(): array
    {
        return [
            'birth' => [
                'date_of_birth' => '1973-12-16',
                'time_of_birth' => '06:45',
                'place_of_birth' => 'Chennai, Tamil Nadu',
                'latitude' => 13.0827,
                'longitude' => 80.2707,
            ],
            'identity' => [
                'lagnam' => 'Aries',
                'lagnam_lord' => 'Mars',
                'rasi' => 'Pisces',
                'rasi_lord' => 'Jupiter',
                'nakshatra' => 'Purva Bhadrapad',
                'nakshatra_lord' => 'Jupiter',
                'nakshatra_pada' => 4,
            ],
            'dasha' => [
                'current_major_lord' => 'Venus',
                'major' => [
                    ['planet' => 'Venus', 'planet_id' => 5, 'start' => '8-7-2019 18:21', 'end' => '8-7-2039 18:21'],
                ],
            ],
            'chart' => [],
            'planets' => [
                ['id' => 5, 'name' => 'Venus', 'house' => 6],
            ],
        ];
    }

    private function fakeNakshatraLagnamWorkbook(): void
    {
        $directory = $this->predictionDataDirectory();

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Rule001');
        $sheet->fromArray([
            ['Nakshatra', 'Rasi', 'Lagnam', 'VideoId'],
            ['Swati', 'Pisces', 'Aries', '34'],
            ['Poorvabhadrapada', 'Pisces', 'Aries', '34'],
        ]);

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
            array_merge(['MEENAM', '6', $yearHeader], $videoIds),
        ];
    }

    private function predictionDataDirectory(): string
    {
        return storage_path('framework/testing/prediction-result-data');
    }
}
