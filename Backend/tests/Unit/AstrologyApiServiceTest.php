<?php

namespace Tests\Unit;

use App\Services\AstrologyApiService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AstrologyApiServiceTest extends TestCase
{
    public function test_past_prediction_bundle_does_not_call_geo_details(): void
    {
        config([
            'services.astrology.base_url' => 'https://json.astrologyapi.com/v1',
            'services.astrology.access_token' => 'test-token',
        ]);

        Http::fake([
            'https://json.astrologyapi.com/v1/*' => Http::response(['ok' => true], 200),
        ]);

        app(AstrologyApiService::class)->fetchPastPredictionBundle([
            'day' => 1,
            'month' => 1,
            'year' => 1990,
            'hour' => 10,
            'min' => 30,
            'lat' => 13.0827,
            'lon' => 80.2707,
            'tzone' => 5.5,
        ]);

        Http::assertSentCount(5);
        Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/geo_details'));
        Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/sarvashtak'));
    }

    public function test_fetch_sarvashtak_posts_only_the_official_endpoint(): void
    {
        config([
            'services.astrology.base_url' => 'https://json.astrologyapi.com/v1',
            'services.astrology.access_token' => 'test-token',
        ]);

        Http::fake([
            'https://json.astrologyapi.com/v1/sarvashtak' => Http::response([
                'ashtak_varga' => ['type' => 'Sarvashtak'],
                'ashtak_points' => ['aries' => ['total' => 25]],
            ], 200),
        ]);

        $payload = [
            'day' => 1,
            'month' => 1,
            'year' => 1990,
            'hour' => 10,
            'min' => 30,
            'lat' => 13.0827,
            'lon' => 80.2707,
            'tzone' => 5.5,
        ];

        $response = app(AstrologyApiService::class)->fetchSarvashtak($payload);

        $this->assertSame(25, $response['ashtak_points']['aries']['total']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://json.astrologyapi.com/v1/sarvashtak'
            && $request['day'] === 1
            && $request['tzone'] === 5.5);
    }
}
