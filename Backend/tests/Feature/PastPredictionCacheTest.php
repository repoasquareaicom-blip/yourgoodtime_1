<?php

namespace Tests\Feature;

use App\Models\PredictionRequest;
use App\Services\PredictionCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PastPredictionCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_unique_request_calls_astrology_api_and_returns_safe_response(): void
    {
        $this->fakeAstrologyApi();

        $response = $this->postJson('/api/predictions/past', $this->payload());

        $response
            ->assertCreated()
            ->assertJson([
                'success' => true,
                'status' => 'completed',
                'cached' => false,
            ])
            ->assertJsonMissingPath('raw_astrology_response')
            ->assertJsonMissingPath('normalized_astrology_data');

        Http::assertSentCount(5);
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/geo_details'));

        $request = PredictionRequest::query()->firstOrFail();
        $this->assertFalse($request->cache_hit);
        $this->assertNotNull($request->chart_fingerprint);
        $this->assertNotNull($request->raw_astrology_response);
        $this->assertNotNull($request->normalized_astrology_data);
    }

    public function test_duplicate_request_reuses_completed_astrology_data_and_preserves_new_personal_data(): void
    {
        $this->fakeAstrologyApi();

        $this->postJson('/api/predictions/past', $this->payload([
            'full_name' => 'First Person',
            'email' => 'first@example.com',
            'marriage_status' => 'married',
        ]))->assertCreated();

        Http::assertSentCount(5);
        Http::fake();

        $response = $this->postJson('/api/predictions/past', $this->payload([
            'full_name' => 'Second Person',
            'email' => 'second@example.com',
            'marriage_status' => 'unmarried',
            'birth_place' => 'Chennai, Tamil Nadu',
            'country' => 'India',
            'country_code' => 'in',
        ]));

        $response
            ->assertCreated()
            ->assertJson([
                'success' => true,
                'status' => 'completed',
                'cached' => true,
            ])
            ->assertJsonMissingPath('raw_astrology_response')
            ->assertJsonMissingPath('normalized_astrology_data');

        Http::assertNothingSent();
        $this->assertCount(2, PredictionRequest::all());

        $first = PredictionRequest::query()->oldest('id')->firstOrFail();
        $second = PredictionRequest::query()->latest('id')->firstOrFail();

        $this->assertSame('Second Person', $second->full_name);
        $this->assertSame('second@example.com', $second->email);
        $this->assertSame('unmarried', $second->marriage_status);
        $this->assertSame('Chennai, Tamil Nadu', $second->place_of_birth);
        $this->assertSame('IN', $second->country_code);
        $this->assertTrue($second->cache_hit);
        $this->assertTrue($second->reused_from_request_id === $first->id);
        $this->assertSame($first->raw_astrology_response, $second->raw_astrology_response);
        $this->assertSame($first->normalized_astrology_data, $second->normalized_astrology_data);
    }

    public function test_slight_coordinate_differences_within_rounding_precision_reuse_chart(): void
    {
        $this->fakeAstrologyApi();

        $this->postJson('/api/predictions/past', $this->payload([
            'latitude' => 13.082701,
            'longitude' => 80.270701,
        ]))->assertCreated();

        Http::fake();

        $this->postJson('/api/predictions/past', $this->payload([
            'latitude' => 13.082704,
            'longitude' => 80.270704,
            'full_name' => 'Rounded Reuse',
            'email' => 'rounded@example.com',
        ]))
            ->assertCreated()
            ->assertJson(['cached' => true]);

        Http::assertNothingSent();
    }

    public function test_meaningful_coordinate_difference_creates_new_provider_request(): void
    {
        $this->fakeAstrologyApi();

        $this->postJson('/api/predictions/past', $this->payload())->assertCreated();
        Http::assertSentCount(5);

        $this->fakeAstrologyApi();

        $this->postJson('/api/predictions/past', $this->payload([
            'latitude' => 13.08279,
            'longitude' => 80.27079,
            'email' => 'changed@example.com',
        ]))
            ->assertCreated()
            ->assertJson(['cached' => false]);

        Http::assertSentCount(5);
        $this->assertCount(2, PredictionRequest::all());
    }

    public function test_failed_and_pending_records_are_not_reused(): void
    {
        $fingerprint = app(PredictionCacheService::class)->fingerprint($this->payload());

        PredictionRequest::query()->create($this->storedPredictionAttributes([
            'status' => 'failed',
            'chart_fingerprint' => $fingerprint,
            'raw_astrology_response' => ['failed' => true],
            'normalized_astrology_data' => ['failed' => true],
        ]));

        PredictionRequest::query()->create($this->storedPredictionAttributes([
            'status' => 'pending',
            'email' => 'pending@example.com',
            'chart_fingerprint' => $fingerprint,
            'raw_astrology_response' => ['pending' => true],
            'normalized_astrology_data' => ['pending' => true],
        ]));

        $this->fakeAstrologyApi();

        $this->postJson('/api/predictions/past', $this->payload())
            ->assertCreated()
            ->assertJson(['cached' => false]);

        Http::assertSentCount(5);
        $this->assertCount(3, PredictionRequest::all());
    }

    public function test_completed_records_missing_cached_json_are_not_reused(): void
    {
        $fingerprint = app(PredictionCacheService::class)->fingerprint($this->payload());

        PredictionRequest::query()->create($this->storedPredictionAttributes([
            'status' => 'completed',
            'chart_fingerprint' => $fingerprint,
            'raw_astrology_response' => null,
            'normalized_astrology_data' => null,
        ]));

        $this->fakeAstrologyApi();

        $this->postJson('/api/predictions/past', $this->payload())
            ->assertCreated()
            ->assertJson(['cached' => false]);

        Http::assertSentCount(5);
    }

    public function test_fingerprint_ignores_personal_auth_product_payment_and_prediction_type_fields(): void
    {
        $cache = app(PredictionCacheService::class);

        $first = $cache->fingerprint($this->payload([
            'full_name' => 'Past Buyer',
            'email' => 'past@example.com',
            'prediction_type' => 'past',
            'user_id' => 10,
            'product_id' => 20,
            'payment_transaction_id' => 30,
            'prediction_entitlement_id' => 40,
            'marriage_status' => 'married',
        ]));

        $second = $cache->fingerprint($this->payload([
            'full_name' => 'Future Buyer',
            'email' => 'future@example.com',
            'prediction_type' => 'future',
            'user_id' => 99,
            'product_id' => 88,
            'payment_transaction_id' => 77,
            'prediction_entitlement_id' => 66,
            'marriage_status' => 'unmarried',
        ]));

        $this->assertSame($first, $second);
    }

    public function test_reusable_api_result_is_not_limited_to_past_prediction_type(): void
    {
        $cache = app(PredictionCacheService::class);
        $fingerprint = $cache->fingerprint($this->payload());

        $source = PredictionRequest::query()->create($this->storedPredictionAttributes([
            'prediction_type' => 'future',
            'chart_fingerprint' => $fingerprint,
            'raw_astrology_response' => ['future' => true],
            'normalized_astrology_data' => ['future' => true],
        ]));

        $reusable = $cache->findReusableCompleted($fingerprint);

        $this->assertNotNull($reusable);
        $this->assertTrue($reusable->is($source));
    }

    public function test_reused_from_request_id_points_to_original_matching_fingerprint_source(): void
    {
        $cache = app(PredictionCacheService::class);
        $fingerprint = $cache->fingerprint($this->payload());

        $original = PredictionRequest::query()->create($this->storedPredictionAttributes([
            'chart_fingerprint' => $fingerprint,
            'raw_astrology_response' => ['source' => 'original'],
            'normalized_astrology_data' => ['source' => 'original'],
            'completed_at' => now()->subMinutes(2),
        ]));

        $intermediate = PredictionRequest::query()->create($this->storedPredictionAttributes([
            'email' => 'intermediate@example.com',
            'chart_fingerprint' => $fingerprint,
            'raw_astrology_response' => ['source' => 'original'],
            'normalized_astrology_data' => ['source' => 'original'],
            'cache_hit' => true,
            'reused_from_request_id' => $original->id,
            'completed_at' => now()->subMinute(),
        ]));

        $target = PredictionRequest::query()->create($this->storedPredictionAttributes([
            'email' => 'target@example.com',
            'chart_fingerprint' => $fingerprint,
            'status' => 'pending',
            'raw_astrology_response' => null,
            'normalized_astrology_data' => null,
            'completed_at' => null,
        ]));

        $cache->copyReusableAstrologyData($target, $intermediate);

        $target->refresh();
        $this->assertTrue($target->cache_hit);
        $this->assertSame($original->id, $target->reused_from_request_id);
        $this->assertSame($original->chart_fingerprint, $target->chart_fingerprint);
    }

    private function fakeAstrologyApi(): void
    {
        config([
            'services.astrology.base_url' => 'https://json.astrologyapi.com/v1',
            'services.astrology.access_token' => 'test-token',
        ]);

        Http::fake([
            'https://json.astrologyapi.com/v1/astro_details' => Http::response([
                'ascendant' => 'Leo',
                'sign' => 'Cancer',
                'Naksahtra' => 'Pushya',
            ], 200),
            'https://json.astrologyapi.com/v1/planets' => Http::response([
                ['name' => 'Sun', 'sign' => 'Aries'],
            ], 200),
            'https://json.astrologyapi.com/v1/horo_chart/D1' => Http::response([
                'chart' => 'D1',
            ], 200),
            'https://json.astrologyapi.com/v1/current_vdasha' => Http::response([
                'major' => ['planet' => 'Saturn'],
                'minor' => ['planet' => 'Mercury'],
            ], 200),
            'https://json.astrologyapi.com/v1/major_vdasha' => Http::response([
                ['planet' => 'Saturn', 'start' => '2020-01-01'],
            ], 200),
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'full_name' => 'Test User',
            'gender' => 'male',
            'date_of_birth' => '1990-01-01',
            'time_of_birth' => '10:30',
            'birth_place' => 'Chennai',
            'country' => 'India',
            'country_code' => 'IN',
            'latitude' => 13.0827,
            'longitude' => 80.2707,
            'timezone' => 'Asia/Kolkata',
            'marriage_status' => 'married',
            'email' => 'test@example.com',
        ], $overrides);
    }

    private function storedPredictionAttributes(array $overrides = []): array
    {
        return array_merge([
            'request_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'chart_fingerprint' => hash('sha256', 'unused'),
            'prediction_type' => 'past',
            'status' => 'completed',
            'full_name' => 'Stored User',
            'gender' => 'female',
            'date_of_birth' => '1990-01-01',
            'time_of_birth' => '10:30',
            'timezone' => 'Asia/Kolkata',
            'place_of_birth' => 'Chennai',
            'country' => 'India',
            'country_code' => 'IN',
            'latitude' => 13.0827,
            'longitude' => 80.2707,
            'marriage_status' => 'married',
            'email' => 'stored@example.com',
            'birth_payload' => ['day' => 1],
            'raw_astrology_response' => ['stored' => true],
            'normalized_astrology_data' => ['stored' => true],
            'api_call_count' => 5,
            'cache_hit' => false,
            'submitted_at' => now(),
            'completed_at' => now(),
        ], $overrides);
    }
}
