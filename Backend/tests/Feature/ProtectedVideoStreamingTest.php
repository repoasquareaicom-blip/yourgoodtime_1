<?php

namespace Tests\Feature;

use App\Models\PredictionRequest;
use App\Models\PredictionResult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProtectedVideoStreamingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('protected_videos');
        config([
            'video.protected_disk' => 'protected_videos',
            'video.public_contents_fallback' => false,
            'video.token_ttl_seconds' => 600,
        ]);
    }

    public function test_authenticated_user_receives_short_lived_playback_url_for_owned_prediction_video(): void
    {
        $user = User::factory()->create(['email' => 'ravi@example.com']);
        $predictionRequest = $this->predictionRequestFor($user);
        $result = $this->predictionResultFor($predictionRequest);

        Storage::disk('protected_videos')->put('00353_final.mp4', '0123456789abcdef');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/video/playback-url', [
            'prediction_request_id' => $predictionRequest->id,
            'request_uuid' => $predictionRequest->request_uuid,
            'prediction_type' => 'past',
            'prediction_result_id' => $result->id,
            'video_id' => '00353_final',
            'video_role' => 'PREDICTION',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.debug.video_id', '00353_final')
            ->assertJsonPath('data.debug.stored_video_id', '00353_final')
            ->assertJsonPath('data.debug.final_video_id', '00353_final')
            ->assertJsonPath('data.debug.video_id_source', 'STORED_RESULT')
            ->assertJsonPath('data.debug.filename', '00353_final.mp4')
            ->assertJsonPath('data.debug.video_role', 'PREDICTION')
            ->assertJsonPath('data.debug.prediction_period', 'past')
            ->assertJsonPath('data.debug.language', 'ta')
            ->assertJsonPath('data.debug.excel_file_path', 'prediction-data/past/51 TO 60.xlsx')
            ->assertJsonPath('data.debug.prediction_request_id', $predictionRequest->id)
            ->assertJsonPath('data.debug.prediction_result_id', $result->id)
            ->assertJsonPath('data.debug.request_uuid', $predictionRequest->request_uuid)
            ->assertJsonPath('data.expires_in', 600);

        $this->assertStringContainsString('/api/video/stream?token=', $response->json('data.url'));
    }

    public function test_playback_url_accepts_bearer_token_from_dev_production_origin_without_csrf_cookie(): void
    {
        config(['sanctum.stateful' => ['dev.yourgoodtime.in']]);

        $user = User::factory()->create(['email' => 'ravi@example.com']);
        $predictionRequest = $this->predictionRequestFor($user);
        $result = $this->predictionResultFor($predictionRequest, predictionVideoId: '353');
        $token = $user->createToken('feature-test-token')->plainTextToken;
        Storage::disk('protected_videos')->put('00353_final.mp4', 'video-bytes');

        $this
            ->withHeaders([
                'Authorization' => 'Bearer '.$token,
                'Origin' => 'https://dev.yourgoodtime.in',
            ])
            ->postJson('/api/video/playback-url', [
                'prediction_request_id' => $predictionRequest->id,
                'request_uuid' => $predictionRequest->request_uuid,
                'prediction_type' => 'past',
                'prediction_result_id' => $result->id,
                'video_id' => '353',
                'video_role' => 'PREDICTION',
                'language' => 'ta',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_working_authenticated_payment_post_uses_bearer_without_csrf_cookie_from_dev_origin(): void
    {
        config(['sanctum.stateful' => ['dev.yourgoodtime.in']]);

        $user = User::factory()->create(['email' => 'ravi@example.com']);
        $token = $user->createToken('feature-test-token')->plainTextToken;

        $this
            ->withHeaders([
                'Authorization' => 'Bearer '.$token,
                'Origin' => 'https://dev.yourgoodtime.in',
            ])
            ->postJson('/api/payments/future-prediction/order', [])
            ->assertStatus(422);
    }

    public function test_playback_url_rejects_video_not_present_in_authorized_result(): void
    {
        $user = User::factory()->create(['email' => 'ravi@example.com']);
        $predictionRequest = $this->predictionRequestFor($user);
        $this->predictionResultFor($predictionRequest);

        Sanctum::actingAs($user);

        $this->postJson('/api/video/playback-url', [
            'prediction_request_id' => $predictionRequest->id,
            'request_uuid' => $predictionRequest->request_uuid,
            'prediction_type' => 'past',
            'video_id' => '99999_final',
        ])->assertForbidden();
    }

    public function test_playback_url_rejects_path_traversal_video_identifier(): void
    {
        $user = User::factory()->create(['email' => 'ravi@example.com']);
        $predictionRequest = $this->predictionRequestFor($user);

        Sanctum::actingAs($user);

        $this->postJson('/api/video/playback-url', [
            'prediction_request_id' => $predictionRequest->id,
            'request_uuid' => $predictionRequest->request_uuid,
            'prediction_type' => 'past',
            'video_id' => '../00353_final',
        ])->assertForbidden();
    }

    public function test_eligible_user_can_stream_their_identified_intro_video(): void
    {
        $user = User::factory()->create(['email' => 'ravi@example.com']);
        $predictionRequest = $this->predictionRequestFor($user);
        $result = $this->predictionResultFor($predictionRequest, identityVideoId: '353');

        Storage::disk('protected_videos')->put('00353_en-IN-dubbed_video_final.mp4', 'intro-video-bytes');
        Sanctum::actingAs($user);

        $playbackUrl = $this->postJson('/api/video/playback-url', [
            'prediction_request_id' => $predictionRequest->id,
            'request_uuid' => $predictionRequest->request_uuid,
            'prediction_type' => 'past',
            'prediction_result_id' => $result->id,
            'video_id' => '353',
            'video_role' => 'IDENTITY',
            'language' => 'en',
        ])
            ->assertOk()
            ->assertJsonPath('data.debug.video_id', '353')
            ->assertJsonPath('data.debug.stored_video_id', '353')
            ->assertJsonPath('data.debug.final_video_id', '353')
            ->assertJsonPath('data.debug.video_id_source', 'IDENTITY_VIDEO')
            ->assertJsonPath('data.debug.filename', '00353_en-IN-dubbed_video_final.mp4')
            ->assertJsonPath('data.debug.video_role', 'IDENTITY')
            ->assertJsonPath('data.debug.prediction_period', 'past')
            ->assertJsonPath('data.debug.language', 'en')
            ->assertJsonPath('data.debug.excel_file_path', 'prediction-data/nakshatra_lagnam.xlsx')
            ->assertJsonPath('data.debug.prediction_request_id', $predictionRequest->id)
            ->assertJsonPath('data.debug.prediction_result_id', $result->id)
            ->assertJsonPath('data.debug.request_uuid', $predictionRequest->request_uuid)
            ->json('data.url');

        $query = [];
        parse_str(parse_url($playbackUrl, PHP_URL_QUERY) ?: '', $query);

        $response = $this->get('/api/video/stream?token='.rawurlencode($query['token']));

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'video/mp4')
            ->assertHeader('Content-Length', (string) strlen('intro-video-bytes'));

        $this->assertSame('intro-video-bytes', $response->streamedContent());
    }

    public function test_intro_playback_is_authorized_from_identity_video_column_when_payload_identity_is_missing(): void
    {
        $user = User::factory()->create(['email' => 'ravi@example.com']);
        $predictionRequest = $this->predictionRequestFor($user);
        $result = $this->predictionResultFor($predictionRequest, identityVideoId: '34');
        $payload = $result->result_payload;
        unset($payload['identity_video']);
        $result->forceFill(['result_payload' => $payload])->save();

        Storage::disk('protected_videos')->put('00034_final.mp4', 'intro-video-bytes');
        Sanctum::actingAs($user);

        $playbackUrl = $this->postJson('/api/video/playback-url', [
            'prediction_request_id' => $predictionRequest->id,
            'request_uuid' => $predictionRequest->request_uuid,
            'prediction_type' => 'past',
            'prediction_result_id' => $result->id,
            'video_id' => '34',
            'video_role' => 'IDENTITY',
            'language' => 'ta',
        ])
            ->assertOk()
            ->assertJsonPath('data.debug.video_id', '34')
            ->assertJsonPath('data.debug.filename', '00034_final.mp4')
            ->assertJsonPath('data.debug.video_role', 'IDENTITY')
            ->json('data.url');

        $query = [];
        parse_str(parse_url($playbackUrl, PHP_URL_QUERY) ?: '', $query);

        $response = $this->get('/api/video/stream?token='.rawurlencode($query['token']));

        $response->assertOk();
        $this->assertSame('intro-video-bytes', $response->streamedContent());
    }

    public function test_intro_playback_uses_request_identity_association_when_selected_past_result_lacks_identity(): void
    {
        $user = User::factory()->create(['email' => 'ravi@example.com']);
        $predictionRequest = $this->predictionRequestFor($user);
        $selectedPastResult = PredictionResult::query()->create([
            'prediction_request_id' => $predictionRequest->id,
            'user_id' => $user->id,
            'prediction_type' => 'past',
            'identity_video_id' => null,
            'result_payload' => [
                'prediction_videos' => [
                    ['video_ids' => ['443'], 'workbook' => '51 TO 60.xlsx'],
                ],
            ],
            'summary_payload' => [],
            'warnings_payload' => [],
            'status' => 'completed',
            'generated_at' => now()->subDay(),
        ]);
        PredictionResult::query()->create([
            'prediction_request_id' => $predictionRequest->id,
            'user_id' => $user->id,
            'prediction_type' => 'future',
            'identity_video_id' => '34',
            'result_payload' => [
                'identity_video' => ['video_id' => '34', 'source' => ['file' => 'nakshatra_lagnam.xlsx']],
                'prediction_videos' => [],
            ],
            'summary_payload' => [],
            'warnings_payload' => [],
            'status' => 'completed',
            'generated_at' => now(),
        ]);

        Storage::disk('protected_videos')->put('00034_en-IN-dubbed_video_final.mp4', 'intro-video-bytes');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/video/playback-url', [
            'prediction_request_id' => $predictionRequest->id,
            'request_uuid' => $predictionRequest->request_uuid,
            'prediction_type' => 'past',
            'prediction_result_id' => $selectedPastResult->id,
            'video_id' => '34',
            'video_role' => 'IDENTITY',
            'language' => 'en',
        ])->assertOk();

        $response
            ->assertJsonPath('data.debug.stored_video_id', '34')
            ->assertJsonPath('data.debug.video_id_source', 'IDENTITY_VIDEO')
            ->assertJsonPath('data.debug.resolved_filename', '00034_en-IN-dubbed_video_final.mp4');
    }

    public function test_intro_playback_uses_language_specific_filename_rules(): void
    {
        $user = User::factory()->create(['email' => 'ravi@example.com']);
        $predictionRequest = $this->predictionRequestFor($user);
        $result = $this->predictionResultFor($predictionRequest, identityVideoId: '353');

        Sanctum::actingAs($user);

        $expectedFilenames = [
            'ta' => '00353_final.mp4',
            'en' => '00353_en-IN-dubbed_video_final.mp4',
            'hi' => '00353_hi-IN-dubbed_video_final.mp4',
        ];

        foreach ($expectedFilenames as $language => $expectedFilename) {
            Storage::disk('protected_videos')->put($expectedFilename, 'video-bytes');

            $playbackUrl = $this->postJson('/api/video/playback-url', [
                'prediction_request_id' => $predictionRequest->id,
                'request_uuid' => $predictionRequest->request_uuid,
                'prediction_type' => 'past',
                'prediction_result_id' => $result->id,
                'video_id' => '353',
                'video_role' => 'IDENTITY',
                'language' => $language,
            ])->assertOk()->json('data.url');

            $query = [];
            parse_str(parse_url($playbackUrl, PHP_URL_QUERY) ?: '', $query);
            $payload = json_decode(Crypt::decryptString($query['token']), true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame('IDENTITY', $payload['video_role']);
            $this->assertSame($language, $payload['language']);
            $this->assertSame($expectedFilename, $payload['filename']);
        }
    }

    public function test_prediction_playback_uses_language_specific_filename_rules(): void
    {
        $user = User::factory()->create(['email' => 'ravi@example.com']);
        $predictionRequest = $this->predictionRequestFor($user);
        $result = $this->predictionResultFor($predictionRequest, predictionVideoId: '353');

        Sanctum::actingAs($user);

        $expectedFilenames = [
            'ta' => '00353_final.mp4',
            'en' => '00353_en-IN-dubbed_video_final.mp4',
            'hi' => '00353_hi-IN-dubbed_video_final.mp4',
        ];

        foreach ($expectedFilenames as $language => $expectedFilename) {
            Storage::disk('protected_videos')->put($expectedFilename, 'video-bytes');

            $playbackUrl = $this->postJson('/api/video/playback-url', [
                'prediction_request_id' => $predictionRequest->id,
                'request_uuid' => $predictionRequest->request_uuid,
                'prediction_type' => 'past',
                'prediction_result_id' => $result->id,
                'video_id' => '353',
                'video_role' => 'PREDICTION',
                'language' => $language,
            ])->assertOk()->json('data.url');

            $query = [];
            parse_str(parse_url($playbackUrl, PHP_URL_QUERY) ?: '', $query);
            $payload = json_decode(Crypt::decryptString($query['token']), true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame('PREDICTION', $payload['video_role']);
            $this->assertSame($language, $payload['language']);
            $this->assertSame($expectedFilename, $payload['filename']);
        }
    }

    public function test_prediction_filename_normalizes_video_ids_with_and_without_leading_zeros(): void
    {
        foreach (['443', '00443'] as $videoId) {
            $user = User::factory()->create(['email' => 'prediction-'.$videoId.'@example.com']);
            $predictionRequest = $this->predictionRequestFor($user);
            $result = $this->predictionResultFor($predictionRequest, predictionVideoId: $videoId);
            Storage::disk('protected_videos')->put('00443_final.mp4', 'video-bytes');

            Sanctum::actingAs($user);

            $playbackUrl = $this->postJson('/api/video/playback-url', [
                'prediction_request_id' => $predictionRequest->id,
                'request_uuid' => $predictionRequest->request_uuid,
                'prediction_type' => 'past',
                'prediction_result_id' => $result->id,
                'video_id' => $videoId,
                'video_role' => 'PREDICTION',
                'language' => 'ta',
            ])->assertOk()->json('data.url');

            $this->assertSame('00443_final.mp4', $this->filenameFromPlaybackUrl($playbackUrl));
        }
    }

    public function test_intro_filename_normalizes_video_ids_with_and_without_leading_zeros_for_all_languages(): void
    {
        $expectedFilenames = [
            'ta' => '00443_final.mp4',
            'en' => '00443_en-IN-dubbed_video_final.mp4',
            'hi' => '00443_hi-IN-dubbed_video_final.mp4',
        ];

        foreach (['443', '00443'] as $videoId) {
            foreach ($expectedFilenames as $language => $expectedFilename) {
                $user = User::factory()->create(['email' => 'intro-'.$videoId.'-'.$language.'@example.com']);
                $predictionRequest = $this->predictionRequestFor($user);
                $result = $this->predictionResultFor($predictionRequest, identityVideoId: $videoId);
                Storage::disk('protected_videos')->put($expectedFilename, 'video-bytes');

                Sanctum::actingAs($user);

                $playbackUrl = $this->postJson('/api/video/playback-url', [
                    'prediction_request_id' => $predictionRequest->id,
                    'request_uuid' => $predictionRequest->request_uuid,
                    'prediction_type' => 'past',
                    'prediction_result_id' => $result->id,
                    'video_id' => $videoId,
                    'video_role' => 'IDENTITY',
                    'language' => $language,
                ])->assertOk()->json('data.url');

                $this->assertSame($expectedFilename, $this->filenameFromPlaybackUrl($playbackUrl));
            }
        }
    }

    public function test_prediction_filename_resolution_uses_existing_padded_or_raw_physical_file(): void
    {
        $cases = [
            ['video_id' => '443', 'existing' => ['00443_final.mp4'], 'expected' => '00443_final.mp4'],
            ['video_id' => '443', 'existing' => ['443_final.mp4'], 'expected' => '443_final.mp4'],
            ['video_id' => '00443', 'existing' => ['00443_final.mp4'], 'expected' => '00443_final.mp4'],
            ['video_id' => '00443', 'existing' => ['443_final.mp4'], 'expected' => '443_final.mp4'],
            ['video_id' => '443', 'existing' => ['00443_final.mp4', '443_final.mp4'], 'expected' => '00443_final.mp4'],
        ];

        foreach ($cases as $index => $case) {
            Storage::fake('protected_videos');

            foreach ($case['existing'] as $filename) {
                Storage::disk('protected_videos')->put($filename, 'video-bytes');
            }

            $user = User::factory()->create(['email' => 'prediction-fallback-'.$index.'@example.com']);
            $predictionRequest = $this->predictionRequestFor($user);
            $result = $this->predictionResultFor($predictionRequest, predictionVideoId: $case['video_id']);

            Sanctum::actingAs($user);

            $response = $this->postJson('/api/video/playback-url', [
                'prediction_request_id' => $predictionRequest->id,
                'request_uuid' => $predictionRequest->request_uuid,
                'prediction_type' => 'past',
                'prediction_result_id' => $result->id,
                'video_id' => $case['video_id'],
                'video_role' => 'PREDICTION',
                'language' => 'ta',
            ])->assertOk();

            $this->assertSame($case['expected'], $this->filenameFromPlaybackUrl($response->json('data.url')));
            $this->assertSame($case['expected'], $response->json('data.debug.filename'));
            $this->assertSame($case['expected'], $response->json('data.debug.resolved_filename'));
            $this->assertSame('padded', $response->json('data.debug.filename_candidates.0.type'));
            $this->assertSame('00443_final.mp4', $response->json('data.debug.filename_candidates.0.filename'));
            $this->assertSame(in_array('00443_final.mp4', $case['existing'], true), $response->json('data.debug.filename_candidates.0.exists'));
            $this->assertSame('raw', $response->json('data.debug.filename_candidates.1.type'));
            $this->assertSame('443_final.mp4', $response->json('data.debug.filename_candidates.1.filename'));
            $this->assertSame(in_array('443_final.mp4', $case['existing'], true), $response->json('data.debug.filename_candidates.1.exists'));
        }
    }

    public function test_prediction_filename_resolution_uses_raw_public_contents_fallback_when_protected_disk_file_is_missing(): void
    {
        config(['video.public_contents_fallback' => true]);

        $legacyDirectory = public_path('contents');
        $legacyPath = $legacyDirectory.DIRECTORY_SEPARATOR.'443_final.mp4';

        if (! is_dir($legacyDirectory)) {
            mkdir($legacyDirectory, 0777, true);
        }

        file_put_contents($legacyPath, 'legacy-video-bytes');

        try {
            $user = User::factory()->create(['email' => 'legacy-fallback@example.com']);
            $predictionRequest = $this->predictionRequestFor($user);
            $result = $this->predictionResultFor($predictionRequest, predictionVideoId: '443');

            Sanctum::actingAs($user);

            $response = $this->postJson('/api/video/playback-url', [
                'prediction_request_id' => $predictionRequest->id,
                'request_uuid' => $predictionRequest->request_uuid,
                'prediction_type' => 'past',
                'prediction_result_id' => $result->id,
                'video_id' => '443',
                'video_role' => 'PREDICTION',
                'language' => 'ta',
            ])->assertOk();

            $this->assertSame('443_final.mp4', $this->filenameFromPlaybackUrl($response->json('data.url')));
            $this->assertSame('443_final.mp4', $response->json('data.debug.filename'));
            $this->assertFalse($response->json('data.debug.filename_candidates.0.exists'));
            $this->assertTrue($response->json('data.debug.filename_candidates.1.exists'));
        } finally {
            if (is_file($legacyPath)) {
                unlink($legacyPath);
            }
        }
    }

    public function test_intro_and_dubbed_filename_resolution_falls_back_to_existing_raw_physical_file(): void
    {
        $cases = [
            [
                'role' => 'IDENTITY',
                'language' => 'ta',
                'existing' => '443_final.mp4',
                'expected' => '443_final.mp4',
                'first_candidate' => '00443_final.mp4',
            ],
            [
                'role' => 'IDENTITY',
                'language' => 'en',
                'existing' => '443_en-IN-dubbed_video_final.mp4',
                'expected' => '443_en-IN-dubbed_video_final.mp4',
                'first_candidate' => '00443_en-IN-dubbed_video_final.mp4',
            ],
            [
                'role' => 'IDENTITY',
                'language' => 'hi',
                'existing' => '443_hi-IN-dubbed_video_final.mp4',
                'expected' => '443_hi-IN-dubbed_video_final.mp4',
                'first_candidate' => '00443_hi-IN-dubbed_video_final.mp4',
            ],
            [
                'role' => 'PREDICTION',
                'language' => 'en',
                'existing' => '443_en-IN-dubbed_video_final.mp4',
                'expected' => '443_en-IN-dubbed_video_final.mp4',
                'first_candidate' => '00443_en-IN-dubbed_video_final.mp4',
            ],
            [
                'role' => 'PREDICTION',
                'language' => 'hi',
                'existing' => '443_hi-IN-dubbed_video_final.mp4',
                'expected' => '443_hi-IN-dubbed_video_final.mp4',
                'first_candidate' => '00443_hi-IN-dubbed_video_final.mp4',
            ],
        ];

        foreach ($cases as $index => $case) {
            Storage::fake('protected_videos');
            Storage::disk('protected_videos')->put($case['existing'], 'video-bytes');

            $user = User::factory()->create(['email' => 'intro-dubbed-fallback-'.$index.'@example.com']);
            $predictionRequest = $this->predictionRequestFor($user);
            $result = $this->predictionResultFor(
                $predictionRequest,
                identityVideoId: '443',
                predictionVideoId: '443'
            );

            Sanctum::actingAs($user);

            $response = $this->postJson('/api/video/playback-url', [
                'prediction_request_id' => $predictionRequest->id,
                'request_uuid' => $predictionRequest->request_uuid,
                'prediction_type' => 'past',
                'prediction_result_id' => $result->id,
                'video_id' => '443',
                'video_role' => $case['role'],
                'language' => $case['language'],
            ])->assertOk();

            $this->assertSame($case['expected'], $this->filenameFromPlaybackUrl($response->json('data.url')));
            $this->assertSame($case['expected'], $response->json('data.debug.filename'));
            $this->assertSame($case['first_candidate'], $response->json('data.debug.filename_candidates.0.filename'));
            $this->assertFalse($response->json('data.debug.filename_candidates.0.exists'));
            $this->assertSame($case['expected'], $response->json('data.debug.filename_candidates.1.filename'));
            $this->assertTrue($response->json('data.debug.filename_candidates.1.exists'));
        }
    }

    public function test_filename_resolution_prefers_padded_physical_file_for_intro_and_dubbed_videos_when_both_exist(): void
    {
        $cases = [
            ['role' => 'IDENTITY', 'language' => 'ta', 'padded' => '00443_final.mp4', 'raw' => '443_final.mp4'],
            ['role' => 'IDENTITY', 'language' => 'en', 'padded' => '00443_en-IN-dubbed_video_final.mp4', 'raw' => '443_en-IN-dubbed_video_final.mp4'],
            ['role' => 'IDENTITY', 'language' => 'hi', 'padded' => '00443_hi-IN-dubbed_video_final.mp4', 'raw' => '443_hi-IN-dubbed_video_final.mp4'],
            ['role' => 'PREDICTION', 'language' => 'en', 'padded' => '00443_en-IN-dubbed_video_final.mp4', 'raw' => '443_en-IN-dubbed_video_final.mp4'],
            ['role' => 'PREDICTION', 'language' => 'hi', 'padded' => '00443_hi-IN-dubbed_video_final.mp4', 'raw' => '443_hi-IN-dubbed_video_final.mp4'],
        ];

        foreach ($cases as $index => $case) {
            Storage::fake('protected_videos');
            Storage::disk('protected_videos')->put($case['padded'], 'padded-video-bytes');
            Storage::disk('protected_videos')->put($case['raw'], 'raw-video-bytes');

            $user = User::factory()->create(['email' => 'padded-preferred-'.$index.'@example.com']);
            $predictionRequest = $this->predictionRequestFor($user);
            $result = $this->predictionResultFor(
                $predictionRequest,
                identityVideoId: '443',
                predictionVideoId: '443'
            );

            Sanctum::actingAs($user);

            $response = $this->postJson('/api/video/playback-url', [
                'prediction_request_id' => $predictionRequest->id,
                'request_uuid' => $predictionRequest->request_uuid,
                'prediction_type' => 'past',
                'prediction_result_id' => $result->id,
                'video_id' => '443',
                'video_role' => $case['role'],
                'language' => $case['language'],
            ])->assertOk();

            $this->assertSame($case['padded'], $this->filenameFromPlaybackUrl($response->json('data.url')));
            $this->assertSame($case['padded'], $response->json('data.debug.filename'));
            $this->assertTrue($response->json('data.debug.filename_candidates.0.exists'));
            $this->assertTrue($response->json('data.debug.filename_candidates.1.exists'));
        }
    }

    public function test_intro_playback_debug_reports_missing_padded_and_raw_candidates_when_unavailable(): void
    {
        $user = User::factory()->create(['email' => 'ravi@example.com']);
        $predictionRequest = $this->predictionRequestFor($user);
        $result = $this->predictionResultFor($predictionRequest, identityVideoId: '34');

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/video/playback-url', [
            'prediction_request_id' => $predictionRequest->id,
            'request_uuid' => $predictionRequest->request_uuid,
            'prediction_type' => 'past',
            'prediction_result_id' => $result->id,
            'video_id' => '34',
            'video_role' => 'IDENTITY',
            'language' => 'ta',
        ])->assertNotFound();

        $response
            ->assertJsonPath('data.debug.authorization_result', 'PASSED')
            ->assertJsonPath('data.debug.availability_result', 'UNAVAILABLE')
            ->assertJsonPath('data.debug.video_id', '34')
            ->assertJsonPath('data.debug.video_role', 'IDENTITY')
            ->assertJsonPath('data.debug.filename', '00034_final.mp4')
            ->assertJsonPath('data.debug.resolved_filename', '00034_final.mp4')
            ->assertJsonPath('data.debug.filename_candidates.0.type', 'padded')
            ->assertJsonPath('data.debug.filename_candidates.0.filename', '00034_final.mp4')
            ->assertJsonPath('data.debug.filename_candidates.0.exists', false)
            ->assertJsonPath('data.debug.filename_candidates.1.type', 'raw')
            ->assertJsonPath('data.debug.filename_candidates.1.filename', '34_final.mp4')
            ->assertJsonPath('data.debug.filename_candidates.1.exists', false);
    }

    public function test_identity_playback_resolves_confirmed_filename_variants_and_normalized_authorization(): void
    {
        $cases = [
            ['stored' => '34', 'requested' => '34', 'language' => 'ta', 'existing' => ['00034_final.mp4'], 'expected' => '00034_final.mp4'],
            ['stored' => '00034', 'requested' => '34', 'language' => 'ta', 'existing' => ['00034_final.mp4'], 'expected' => '00034_final.mp4'],
            ['stored' => '34', 'requested' => '34', 'language' => 'ta', 'existing' => ['34_final.mp4'], 'expected' => '34_final.mp4'],
            ['stored' => '34', 'requested' => '34', 'language' => 'en', 'existing' => ['00034_en-IN-dubbed_video_final.mp4'], 'expected' => '00034_en-IN-dubbed_video_final.mp4'],
            ['stored' => '34', 'requested' => '34', 'language' => 'en', 'existing' => ['34_en-IN-dubbed_video_final.mp4'], 'expected' => '34_en-IN-dubbed_video_final.mp4'],
            ['stored' => '34', 'requested' => '34', 'language' => 'hi', 'existing' => ['00034_hi-IN-dubbed_video_final.mp4'], 'expected' => '00034_hi-IN-dubbed_video_final.mp4'],
            ['stored' => '34', 'requested' => '00034', 'language' => 'ta', 'existing' => ['00034_final.mp4'], 'expected' => '00034_final.mp4'],
            ['stored' => '34', 'requested' => '34', 'language' => 'ta', 'existing' => ['00034_final.mp4', '34_final.mp4'], 'expected' => '00034_final.mp4'],
        ];

        foreach ($cases as $index => $case) {
            Storage::fake('protected_videos');

            foreach ($case['existing'] as $filename) {
                Storage::disk('protected_videos')->put($filename, 'identity-video-bytes');
            }

            $user = User::factory()->create(['email' => 'identity-case-'.$index.'@example.com']);
            $predictionRequest = $this->predictionRequestFor($user);
            $result = $this->predictionResultFor($predictionRequest, identityVideoId: $case['stored']);

            Sanctum::actingAs($user);

            $response = $this->postJson('/api/video/playback-url', [
                'prediction_request_id' => $predictionRequest->id,
                'request_uuid' => $predictionRequest->request_uuid,
                'prediction_type' => 'past',
                'prediction_result_id' => $result->id,
                'video_id' => $case['requested'],
                'video_role' => 'IDENTITY',
                'language' => $case['language'],
            ])->assertOk();

            $this->assertSame($case['expected'], $this->filenameFromPlaybackUrl($response->json('data.url')));
            $this->assertSame($case['stored'], $response->json('data.debug.stored_video_id'));
            $this->assertSame($case['requested'], $response->json('data.debug.final_video_id'));
            $this->assertSame('IDENTITY_VIDEO', $response->json('data.debug.video_id_source'));
            $this->assertSame($case['expected'], $response->json('data.debug.resolved_filename'));
        }
    }

    public function test_identity_authorization_rejects_different_logical_video_id_with_safe_debug(): void
    {
        $user = User::factory()->create(['email' => 'ravi@example.com']);
        $predictionRequest = $this->predictionRequestFor($user);
        $result = $this->predictionResultFor($predictionRequest, identityVideoId: '34');

        Storage::disk('protected_videos')->put('00035_final.mp4', 'other-video-bytes');
        Sanctum::actingAs($user);

        $this->postJson('/api/video/playback-url', [
            'prediction_request_id' => $predictionRequest->id,
            'request_uuid' => $predictionRequest->request_uuid,
            'prediction_type' => 'past',
            'prediction_result_id' => $result->id,
            'video_id' => '35',
            'video_role' => 'IDENTITY',
            'language' => 'ta',
        ])
            ->assertForbidden()
            ->assertJsonPath('data.debug.authorization_result', 'FAILED')
            ->assertJsonPath('data.debug.authorization_reason', 'IDENTITY_VIDEO_MISMATCH')
            ->assertJsonPath('data.debug.requested_video_id', '35')
            ->assertJsonPath('data.debug.stored_video_id', '34');
    }

    public function test_user_cannot_arbitrarily_request_any_intro_video_number(): void
    {
        $user = User::factory()->create(['email' => 'ravi@example.com']);
        $predictionRequest = $this->predictionRequestFor($user);
        $result = $this->predictionResultFor($predictionRequest, identityVideoId: '353');

        Storage::disk('protected_videos')->put('00354_final.mp4', 'other-video-bytes');
        Sanctum::actingAs($user);

        $this->postJson('/api/video/playback-url', [
            'prediction_request_id' => $predictionRequest->id,
            'request_uuid' => $predictionRequest->request_uuid,
            'prediction_type' => 'past',
            'prediction_result_id' => $result->id,
            'video_id' => '354',
            'video_role' => 'IDENTITY',
            'language' => 'ta',
        ])->assertForbidden();
    }

    public function test_another_user_cannot_request_someone_elses_intro_video(): void
    {
        $owner = User::factory()->create(['email' => 'ravi@example.com']);
        $otherUser = User::factory()->create(['email' => 'meena@example.com']);
        $predictionRequest = $this->predictionRequestFor($owner);
        $result = $this->predictionResultFor($predictionRequest, identityVideoId: '353');

        Sanctum::actingAs($otherUser);

        $this->postJson('/api/video/playback-url', [
            'prediction_request_id' => $predictionRequest->id,
            'request_uuid' => $predictionRequest->request_uuid,
            'prediction_type' => 'past',
            'prediction_result_id' => $result->id,
            'video_id' => '353',
            'video_role' => 'IDENTITY',
            'language' => 'ta',
        ])->assertForbidden();
    }

    public function test_intro_playback_rejects_wrong_request_uuid(): void
    {
        $user = User::factory()->create(['email' => 'ravi@example.com']);
        $predictionRequest = $this->predictionRequestFor($user);
        $result = $this->predictionResultFor($predictionRequest, identityVideoId: '34');

        Storage::disk('protected_videos')->put('00034_final.mp4', 'intro-video-bytes');
        Sanctum::actingAs($user);

        $this->postJson('/api/video/playback-url', [
            'prediction_request_id' => $predictionRequest->id,
            'request_uuid' => (string) Str::uuid(),
            'prediction_type' => 'past',
            'prediction_result_id' => $result->id,
            'video_id' => '34',
            'video_role' => 'IDENTITY',
            'language' => 'ta',
        ])
            ->assertForbidden()
            ->assertJsonPath('data.debug.authorization_result', 'FAILED')
            ->assertJsonPath('data.debug.authorization_reason', 'REQUEST_UUID_MISMATCH');
    }

    public function test_stream_endpoint_supports_http_range_requests_without_auth_header(): void
    {
        $user = User::factory()->create(['email' => 'ravi@example.com']);
        $predictionRequest = $this->predictionRequestFor($user);
        $result = $this->predictionResultFor($predictionRequest);

        Storage::disk('protected_videos')->put('00353_final.mp4', '0123456789abcdef');
        Sanctum::actingAs($user);

        $playbackUrl = $this->postJson('/api/video/playback-url', [
            'prediction_request_id' => $predictionRequest->id,
            'request_uuid' => $predictionRequest->request_uuid,
            'prediction_type' => 'past',
            'prediction_result_id' => $result->id,
            'video_id' => '00353_final',
        ])->json('data.url');

        $query = [];
        parse_str(parse_url($playbackUrl, PHP_URL_QUERY) ?: '', $query);

        $response = $this->withHeader('Range', 'bytes=2-5')
            ->get('/api/video/stream?token='.rawurlencode($query['token']));

        $response
            ->assertStatus(206)
            ->assertHeader('Content-Type', 'video/mp4')
            ->assertHeader('Accept-Ranges', 'bytes')
            ->assertHeader('Content-Length', '4')
            ->assertHeader('Content-Range', 'bytes 2-5/16');

        $this->assertSame('2345', $response->streamedContent());
    }

    public function test_stream_endpoint_rejects_expired_tokens(): void
    {
        $token = Crypt::encryptString(json_encode([
            'uid' => 1,
            'prediction_request_id' => 1,
            'prediction_result_id' => 1,
            'prediction_type' => 'past',
            'video_id' => '00353_final',
            'filename' => '00353_final.mp4',
            'exp' => now()->subMinute()->getTimestamp(),
            'nonce' => (string) Str::uuid(),
        ], JSON_THROW_ON_ERROR));

        $this->get('/api/video/stream?token='.rawurlencode($token))
            ->assertUnauthorized()
            ->assertJsonPath('success', false);
    }

    private function predictionRequestFor(User $user): PredictionRequest
    {
        return PredictionRequest::query()->create([
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
            'email' => $user->email,
            'birth_payload' => ['day' => 12, 'month' => 4, 'year' => 1990],
            'raw_astrology_response' => ['astro_details' => ['ascendant' => 'Mesha']],
            'normalized_astrology_data' => ['identity' => ['lagnam' => 'Mesha']],
            'submitted_at' => now(),
            'completed_at' => now(),
        ]);
    }

    private function predictionResultFor(
        PredictionRequest $predictionRequest,
        string $identityVideoId = 'INTRO_001',
        string $predictionVideoId = '00353_final'
    ): PredictionResult
    {
        return PredictionResult::query()->create([
            'prediction_request_id' => $predictionRequest->id,
            'user_id' => User::query()->where('email', $predictionRequest->email)->value('id'),
            'prediction_type' => 'past',
            'identity_video_id' => $identityVideoId,
            'result_payload' => [
                'identity_video' => [
                    'video_id' => $identityVideoId,
                    'source' => [
                        'file' => 'nakshatra_lagnam.xlsx',
                    ],
                ],
                'prediction_videos' => [
                    [
                        'video_ids' => [$predictionVideoId],
                        'workbook' => '51 TO 60.xlsx',
                    ],
                ],
            ],
            'summary_payload' => [],
            'warnings_payload' => [],
            'status' => 'completed',
            'generated_at' => now(),
        ]);
    }

    private function filenameFromPlaybackUrl(string $playbackUrl): string
    {
        $query = [];
        parse_str(parse_url($playbackUrl, PHP_URL_QUERY) ?: '', $query);
        $payload = json_decode(Crypt::decryptString($query['token']), true, 512, JSON_THROW_ON_ERROR);

        return $payload['filename'];
    }
}
