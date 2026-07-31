<?php

namespace Tests\Feature;

use App\Models\OtpRecord;
use App\Models\PredictionRequest;
use App\Models\PredictionProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class RegistrationOtpTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_email_cannot_request_another_registration_otp(): void
    {
        PredictionRequest::query()->create($this->storedPredictionAttributes([
            'email' => 'person@example.com',
        ]));

        Http::fake();

        $this->postJson('/api/auth/send-otp', $this->registrationPayload([
            'email' => ' Person@Example.com ',
        ]))
            ->assertStatus(409)
            ->assertExactJson($this->alreadyRegisteredJson());

        $this->assertDatabaseCount('otp_records', 0);
        Http::assertNothingSent();
    }

    public function test_new_email_can_request_registration_otp_with_pending_payload(): void
    {
        $this->fakeMailGateway();

        $this->postJson('/api/auth/send-otp', $this->registrationPayload([
            'email' => ' Person@Example.com ',
        ]))
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'code' => 'OTP_SENT',
                'message' => 'OTP sent successfully.',
                'next_action' => 'VERIFY_REGISTRATION_OTP',
            ]);

        $record = OtpRecord::query()->firstOrFail();

        $this->assertSame('person@example.com', $record->email);
        $this->assertSame('signup', $record->purpose);
        $this->assertSame('person@example.com', $record->pending_payload['email']);
        $this->assertSame('Test User', $record->pending_payload['full_name']);
        $this->assertNull($record->used_at);
    }

    public function test_generic_registration_otp_creates_user_without_prediction_request(): void
    {
        $this->fakeMailGateway();

        $this->postJson('/api/auth/send-otp', [
            'email' => 'new@example.com',
            'name' => 'New User',
            'purpose' => 'signup',
        ])->assertOk();

        $record = OtpRecord::query()->firstOrFail();
        $record->forceFill(['otp_hash' => Hash::make('123456')])->save();

        $this->postJson('/api/auth/verify-otp', [
            'email' => 'new@example.com',
            'otp' => '123456',
            'purpose' => 'signup',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])
            ->assertOk()
            ->assertJsonPath('code', 'REGISTRATION_COMPLETED')
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email']])
            ->assertJsonMissingPath('prediction_request_id');

        $this->assertDatabaseCount('prediction_requests', 0);
        $this->assertDatabaseHas('users', [
            'email' => 'new@example.com',
            'name' => 'New User',
        ]);
    }

    public function test_signup_otp_requires_password_after_otp_verification(): void
    {
        $this->createRegistrationOtp();

        $this->postJson('/api/auth/verify-otp', [
            'email' => 'person@example.com',
            'otp' => '123456',
            'purpose' => 'signup',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('prediction_requests', 0);
    }

    public function test_registration_otp_can_be_validated_before_password_without_being_used(): void
    {
        $record = $this->createRegistrationOtp();

        $this->postJson('/api/auth/validate-otp', [
            'email' => 'person@example.com',
            'otp' => '123456',
            'purpose' => 'signup',
        ])
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'code' => 'OTP_VALID',
                'message' => 'OTP verified successfully.',
            ]);

        $this->assertNull($record->refresh()->used_at);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('prediction_requests', 0);
    }

    public function test_invalid_registration_otp_validation_does_not_open_password_step_or_consume_otp(): void
    {
        $record = $this->createRegistrationOtp();

        $this->postJson('/api/auth/validate-otp', [
            'email' => 'person@example.com',
            'otp' => '222222',
            'purpose' => 'signup',
        ])
            ->assertUnprocessable()
            ->assertExactJson([
                'success' => false,
                'code' => 'INVALID_OTP',
                'message' => 'Invalid or expired OTP.',
            ]);

        $this->assertNull($record->refresh()->used_at);
    }

    public function test_invalid_registration_otp_does_not_create_prediction_request(): void
    {
        $record = $this->createRegistrationOtp();

        $this->postJson('/api/auth/verify-otp', $this->verifyPayload(['otp' => '222222']))
            ->assertUnprocessable()
            ->assertExactJson([
                'success' => false,
                'code' => 'INVALID_OTP',
                'message' => 'The OTP you entered is invalid.',
            ]);

        $this->assertDatabaseCount('prediction_requests', 0);
        $this->assertNull($record->refresh()->used_at);
    }

    public function test_expired_registration_otp_does_not_create_prediction_request(): void
    {
        $record = $this->createRegistrationOtp(['expires_at' => now()->subMinute()]);

        $this->postJson('/api/auth/verify-otp', $this->verifyPayload())
            ->assertUnprocessable()
            ->assertExactJson([
                'success' => false,
                'code' => 'OTP_EXPIRED',
                'message' => 'The OTP has expired. Please request a new OTP.',
            ]);

        $this->assertDatabaseCount('prediction_requests', 0);
        $this->assertNull($record->refresh()->used_at);
    }

    public function test_valid_registration_otp_creates_exactly_one_prediction_request_and_marks_used(): void
    {
        $this->fakeAstrologyApi();
        $record = $this->createRegistrationOtp();

        $response = $this->postJson('/api/auth/verify-otp', $this->verifyPayload())
            ->assertOk()
            ->assertJson([
                'success' => true,
                'code' => 'REGISTRATION_COMPLETED',
                'message' => 'Registration completed successfully.',
            ])
            ->assertJsonStructure(['prediction_request_id', 'request_uuid', 'token', 'token_type', 'user' => ['id', 'name', 'email']])
            ->assertJsonPath('token_type', 'Bearer');

        $this->assertDatabaseCount('prediction_requests', 1);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('users', [
            'email' => 'person@example.com',
            'name' => 'Test User',
            'is_active' => true,
        ]);
        $this->assertTrue(Hash::check('secret123', User::query()->firstOrFail()->password));
        $this->assertNotNull($record->refresh()->used_at);
        $this->assertSame(
            $response->json('prediction_request_id'),
            PredictionRequest::query()->firstOrFail()->id
        );

        $predictionRequest = PredictionRequest::query()->firstOrFail();

        $this->assertSame('completed', $predictionRequest->status);
        $this->assertNotNull($predictionRequest->birth_payload);
        $this->assertNotNull($predictionRequest->raw_astrology_response);
        $this->assertNotNull($predictionRequest->normalized_astrology_data);
        $this->assertSame(5, $predictionRequest->api_call_count);
        $this->assertNotNull($predictionRequest->completed_at);

        Http::assertSentCount(5);
    }

    public function test_registration_token_can_load_future_access_for_same_prediction(): void
    {
        $this->fakeAstrologyApi();
        $this->createRegistrationOtp();

        PredictionProduct::query()->create([
            'code' => 'FUTURE_PREDICTION',
            'name' => 'Future Prediction',
            'description' => 'Unlock future guidance.',
            'prediction_type' => 'future',
            'price_inclusive_tax_minor' => 60000,
            'currency' => 'INR',
            'tax_inclusive' => true,
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/auth/verify-otp', $this->verifyPayload())->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$response->json('token'))
            ->getJson('/api/predictions/future-access?'.http_build_query([
                'prediction_request_id' => $response->json('prediction_request_id'),
                'request_uuid' => $response->json('request_uuid'),
            ]))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('can_purchase', true)
            ->assertJsonPath('product.duration_label', 'Next 1 Years');

        $this->assertSame(
            'person@example.com',
            User::query()->findOrFail($response->json('user.id'))->email
        );
    }

    public function test_registration_otp_remains_unused_when_prediction_creation_fails(): void
    {
        $record = $this->createRegistrationOtp([
            'pending_payload' => array_diff_key($this->pendingPayload(), ['full_name' => true]),
        ]);

        $this->postJson('/api/auth/verify-otp', $this->verifyPayload())
            ->assertStatus(500);

        $this->assertDatabaseCount('prediction_requests', 0);
        $this->assertNull($record->refresh()->used_at);
    }

    public function test_reusing_same_registration_otp_does_not_create_another_prediction_request(): void
    {
        $this->fakeAstrologyApi();
        $this->createRegistrationOtp();

        $this->postJson('/api/auth/verify-otp', $this->verifyPayload())->assertOk();

        $this->postJson('/api/auth/verify-otp', $this->verifyPayload())
            ->assertUnprocessable()
            ->assertExactJson([
                'success' => false,
                'code' => 'OTP_ALREADY_USED',
                'message' => 'This OTP has already been used. Please request a new OTP.',
            ]);

        $this->assertDatabaseCount('prediction_requests', 1);
    }

    public function test_registration_completed_is_not_returned_when_astrology_processing_fails(): void
    {
        Http::fake([
            'https://json.astrologyapi.com/v1/astro_details' => Http::response([
                'message' => 'Provider failed',
            ], 500),
        ]);

        config([
            'services.astrology.base_url' => 'https://json.astrologyapi.com/v1',
            'services.astrology.access_token' => 'test-token',
        ]);

        $record = $this->createRegistrationOtp();

        $this->postJson('/api/auth/verify-otp', $this->verifyPayload())
            ->assertStatus(502)
            ->assertJson([
                'success' => false,
                'status' => 'failed',
                'message' => 'Unable to create past prediction request.',
            ])
            ->assertJsonMissing([
                'code' => 'REGISTRATION_COMPLETED',
            ]);

        $predictionRequest = PredictionRequest::query()->firstOrFail();

        $this->assertSame('failed', $predictionRequest->status);
        $this->assertNull($predictionRequest->raw_astrology_response);
        $this->assertNull($predictionRequest->normalized_astrology_data);
        $this->assertNull($record->refresh()->used_at);
    }

    public function test_existing_email_during_registration_verification_returns_already_registered(): void
    {
        $record = $this->createRegistrationOtp();
        PredictionRequest::query()->create($this->storedPredictionAttributes([
            'email' => 'person@example.com',
        ]));

        $this->postJson('/api/auth/verify-otp', $this->verifyPayload())
            ->assertStatus(409)
            ->assertExactJson($this->alreadyRegisteredJson())
            ->assertDontSee('Your account not found')
            ->assertDontSee('User account not found');

        $this->assertDatabaseCount('prediction_requests', 1);
        $this->assertNull($record->refresh()->used_at);
    }

    private function fakeMailGateway(): void
    {
        config([
            'services.mail_gateway.url' => 'https://api.yourgoodtime.in/api/internal/mail/send-otp',
            'services.mail_gateway.key' => 'configured-test-key',
        ]);

        Http::fake([
            'https://api.yourgoodtime.in/api/internal/mail/send-otp' => Http::response([
                'success' => true,
                'message' => 'OTP email sent successfully.',
            ]),
        ]);
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

    private function registrationPayload(array $overrides = []): array
    {
        return array_merge($this->pendingPayload(), [
            'purpose' => 'signup',
            'birth_place' => 'Chennai',
        ], $overrides);
    }

    private function verifyPayload(array $overrides = []): array
    {
        return array_merge([
            'email' => 'person@example.com',
            'otp' => '123456',
            'purpose' => 'signup',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ], $overrides);
    }

    private function createRegistrationOtp(array $overrides = []): OtpRecord
    {
        return OtpRecord::query()->create(array_merge([
            'email' => 'person@example.com',
            'purpose' => 'signup',
            'otp_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(5),
            'used_at' => null,
            'pending_payload' => $this->pendingPayload(),
        ], $overrides));
    }

    private function pendingPayload(): array
    {
        return [
            'full_name' => 'Test User',
            'gender' => 'male',
            'date_of_birth' => '1990-01-01',
            'time_of_birth' => '10:30',
            'timezone' => 'Asia/Kolkata',
            'place_of_birth' => 'Chennai',
            'country' => 'India',
            'country_code' => 'IN',
            'latitude' => 13.0827,
            'longitude' => 80.2707,
            'marriage_status' => 'married',
            'email' => 'person@example.com',
        ];
    }

    private function storedPredictionAttributes(array $overrides = []): array
    {
        return array_merge([
            'request_uuid' => (string) Str::uuid(),
            'chart_fingerprint' => hash('sha256', 'stored'),
            'prediction_type' => 'past',
            'status' => 'pending',
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
            'cache_hit' => false,
            'submitted_at' => now(),
        ], $overrides);
    }

    private function alreadyRegisteredJson(): array
    {
        return [
            'success' => false,
            'code' => 'EMAIL_ALREADY_REGISTERED',
            'message' => 'This email is already registered. Please log in to continue.',
        ];
    }
}
