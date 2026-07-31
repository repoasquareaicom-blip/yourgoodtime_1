<?php

namespace Tests\Feature;

use App\Models\OtpRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class VerifyOtpTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_otp_is_verified_successfully_and_marked_used(): void
    {
        $record = $this->createOtpRecord();

        $this->postJson('/api/auth/verify-otp', $this->payload())
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'message' => 'OTP verified successfully.',
            ])
            ->assertJsonMissingPath('token');

        $this->assertNotNull($record->refresh()->used_at);
    }

    public function test_verified_otp_cannot_be_reused(): void
    {
        $this->createOtpRecord();

        $this->postJson('/api/auth/verify-otp', $this->payload())
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'message' => 'OTP verified successfully.',
            ]);

        $this->postJson('/api/auth/verify-otp', $this->payload())
            ->assertUnprocessable()
            ->assertExactJson([
                'success' => false,
                'code' => 'INVALID_OTP',
                'message' => 'Invalid or expired OTP.',
            ]);
    }

    public function test_missing_record_returns_invalid_or_expired_otp(): void
    {
        $this->postJson('/api/auth/verify-otp', $this->payload())
            ->assertUnprocessable()
            ->assertExactJson([
                'success' => false,
                'code' => 'INVALID_OTP',
                'message' => 'Invalid or expired OTP.',
            ]);
    }

    public function test_mismatched_otp_returns_invalid_or_expired_otp(): void
    {
        $this->createOtpRecord(['otp_hash' => Hash::make('111111')]);

        $this->postJson('/api/auth/verify-otp', $this->payload(['otp' => '222222']))
            ->assertUnprocessable()
            ->assertExactJson([
                'success' => false,
                'code' => 'INVALID_OTP',
                'message' => 'Invalid or expired OTP.',
            ]);
    }

    public function test_expired_otp_returns_invalid_or_expired_otp(): void
    {
        $this->createOtpRecord(['expires_at' => now()->subMinute()]);

        $this->postJson('/api/auth/verify-otp', $this->payload())
            ->assertUnprocessable()
            ->assertExactJson([
                'success' => false,
                'code' => 'INVALID_OTP',
                'message' => 'Invalid or expired OTP.',
            ]);
    }

    public function test_used_otp_returns_invalid_or_expired_otp(): void
    {
        $this->createOtpRecord(['used_at' => now()]);

        $this->postJson('/api/auth/verify-otp', $this->payload())
            ->assertUnprocessable()
            ->assertExactJson([
                'success' => false,
                'code' => 'INVALID_OTP',
                'message' => 'Invalid or expired OTP.',
            ]);
    }

    public function test_latest_otp_record_is_used_for_verification(): void
    {
        $this->createOtpRecord([
            'otp_hash' => Hash::make('111111'),
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinutes(2),
        ]);
        $this->createOtpRecord([
            'otp_hash' => Hash::make('222222'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/auth/verify-otp', $this->payload(['otp' => '222222']))
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'message' => 'OTP verified successfully.',
            ]);
    }

    public function test_login_otp_returns_not_found_when_user_does_not_exist(): void
    {
        $this->createOtpRecord([
            'purpose' => 'login',
        ]);

        $this->postJson('/api/auth/verify-otp', $this->payload([
            'purpose' => 'login',
        ]))
            ->assertNotFound()
            ->assertExactJson([
                'success' => false,
                'message' => 'User account not found.',
            ]);
    }

    public function test_login_otp_returns_forbidden_when_user_is_inactive(): void
    {
        User::factory()->create([
            'email' => 'person@example.com',
            'is_active' => false,
        ]);
        $record = $this->createOtpRecord([
            'purpose' => 'login',
        ]);

        $this->postJson('/api/auth/verify-otp', $this->payload([
            'purpose' => 'login',
        ]))
            ->assertForbidden()
            ->assertExactJson([
                'success' => false,
                'message' => 'Your account is inactive.',
            ]);

        $this->assertNotNull($record->refresh()->used_at);
    }

    public function test_login_otp_generates_sanctum_token_and_rotates_existing_tokens(): void
    {
        $user = User::factory()->create([
            'name' => 'Test Person',
            'email' => 'person@example.com',
            'is_active' => true,
            'last_login_at' => null,
        ]);
        $oldToken = $user->createToken('old-token');
        $record = $this->createOtpRecord([
            'purpose' => 'login',
        ]);

        $response = $this->postJson('/api/auth/verify-otp', $this->payload([
            'purpose' => 'login',
        ]));

        $response
            ->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Login successful.',
                'token_type' => 'Bearer',
                'user' => [
                    'id' => $user->id,
                    'name' => 'Test Person',
                    'email' => 'person@example.com',
                ],
            ])
            ->assertJsonStructure(['token'])
            ->assertJsonMissingPath('user.password')
            ->assertJsonMissingPath('user.remember_token');

        $this->assertNotSame($oldToken->plainTextToken, $response->json('token'));
        $this->assertNotNull($record->refresh()->used_at);
        $this->assertNotNull($user->refresh()->last_login_at);
        $this->assertSame(1, $user->tokens()->count());
        $this->assertSame('auth-token', $user->tokens()->first()->name);
    }

    public function test_password_login_generates_sanctum_token(): void
    {
        $user = User::factory()->create([
            'name' => 'Password Person',
            'email' => 'person@example.com',
            'password' => Hash::make('secret123'),
            'is_active' => true,
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'person@example.com',
            'password' => 'secret123',
        ])
            ->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Login successful.',
                'token_type' => 'Bearer',
                'user' => [
                    'id' => $user->id,
                    'name' => 'Password Person',
                    'email' => 'person@example.com',
                ],
            ])
            ->assertJsonStructure(['token']);
    }

    public function test_password_reset_uses_otp_and_logs_user_in(): void
    {
        $user = User::factory()->create([
            'email' => 'person@example.com',
            'password' => Hash::make('old-password'),
        ]);
        $this->createOtpRecord(['purpose' => 'password_reset']);

        $this->postJson('/api/auth/verify-otp', [
            'email' => 'person@example.com',
            'otp' => '123456',
            'purpose' => 'password_reset',
            'password' => 'newsecret123',
            'password_confirmation' => 'newsecret123',
        ])
            ->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Password reset successfully.',
                'token_type' => 'Bearer',
            ])
            ->assertJsonStructure(['token']);

        $this->assertTrue(Hash::check('newsecret123', $user->refresh()->password));
    }

    public function test_email_otp_and_purpose_are_validated(): void
    {
        $this->postJson('/api/auth/verify-otp', [
            'email' => 'invalid',
            'otp' => '12345a',
            'purpose' => '',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'otp', 'purpose']);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'email' => 'person@example.com',
            'otp' => '123456',
            'purpose' => 'email_verification',
        ], $overrides);
    }

    private function createOtpRecord(array $overrides = []): OtpRecord
    {
        return OtpRecord::query()->create(array_merge([
            'email' => 'person@example.com',
            'purpose' => 'email_verification',
            'otp_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(5),
            'used_at' => null,
        ], $overrides));
    }
}
