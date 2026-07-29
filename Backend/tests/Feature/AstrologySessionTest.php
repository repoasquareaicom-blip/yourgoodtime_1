<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AstrologySessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_astrology_session_can_start_and_search_places(): void
    {
        Http::fake([
            'https://json.astrologyapi.com/v1/geo_details' => Http::response([
                'data' => [
                    [
                        'place_name' => 'Chennai',
                        'country' => 'India',
                        'country_code' => 'IN',
                        'latitude' => 13.0827,
                        'longitude' => 80.2707,
                    ],
                ],
            ]),
        ]);

        config([
            'services.astrology.base_url' => 'https://json.astrologyapi.com/v1',
            'services.astrology.access_token' => 'test-token',
        ]);

        $sessionUuid = $this->postJson('/api/astrology/sessions')
            ->assertOk()
            ->assertJson([
                'success' => true,
            ])
            ->json('session_uuid');

        $this->assertDatabaseHas('astrology_sessions', [
            'session_uuid' => $sessionUuid,
            'session_type' => 'birth_details',
        ]);

        $this->getJson('/api/astrology/places?search=Chennai&session_uuid='.$sessionUuid)
            ->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    [
                        'place_name' => 'Chennai',
                        'country' => 'India',
                        'country_code' => 'IN',
                    ],
                ],
            ]);

        $this->assertDatabaseHas('astrology_api_logs', [
            'api_name' => 'place_search',
            'request_type' => 'search',
            'search_query' => 'chennai',
            'response_status' => 200,
            'result_count' => 1,
        ]);
    }

    public function test_astrology_place_selection_updates_session(): void
    {
        $sessionUuid = $this->postJson('/api/astrology/sessions')->json('session_uuid');

        $this->postJson('/api/astrology/places/select', [
            'session_uuid' => $sessionUuid,
            'place_name' => 'Chennai',
            'country' => 'India',
            'latitude' => 13.0827,
            'longitude' => 80.2707,
        ])
            ->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Success',
            ]);

        $this->assertDatabaseHas('astrology_sessions', [
            'session_uuid' => $sessionUuid,
            'selected_place_name' => 'Chennai',
            'selected_country' => 'India',
        ]);
    }
}
