<?php

namespace Tests\Feature;

use App\Models\PredictionRequest;
use App\Models\PredictionResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AshtakavargaResearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_research_endpoint_returns_raw_sarvashtak_without_saving_predictions(): void
    {
        config([
            'services.astrology.base_url' => 'https://json.astrologyapi.com/v1',
            'services.astrology.access_token' => 'test-token',
        ]);

        Http::fake([
            'https://json.astrologyapi.com/v1/sarvashtak' => Http::response([
                'ashtak_varga' => ['type' => 'Sarvashtak'],
                'ashtak_points' => [
                    'aries' => [
                        'sun' => 3,
                        'moon' => 3,
                        'mars' => 3,
                        'mercury' => 7,
                        'jupiter' => 4,
                        'venus' => 4,
                        'saturn' => 1,
                        'ascendant' => 0,
                        'total' => 25,
                    ],
                ],
            ], 200),
        ]);

        $this->postJson('/api/research/ashtakavarga', [
            'date_of_birth' => '1990-01-15',
            'time_of_birth' => '10:30',
            'birth_place' => 'Chennai',
            'latitude' => 13.0827,
            'longitude' => 80.2707,
            'timezone' => 'Asia/Kolkata',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('endpoint', 'sarvashtak')
            ->assertJsonPath('data.ashtak_varga.type', 'Sarvashtak')
            ->assertJsonPath('request_payload.day', 15)
            ->assertJsonPath('request_payload.month', 1)
            ->assertJsonPath('request_payload.year', 1990)
            ->assertJsonPath('request_payload.hour', 10)
            ->assertJsonPath('request_payload.min', 30);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://json.astrologyapi.com/v1/sarvashtak');
        $this->assertSame(0, PredictionRequest::query()->count());
        $this->assertSame(0, PredictionResult::query()->count());
    }
}
