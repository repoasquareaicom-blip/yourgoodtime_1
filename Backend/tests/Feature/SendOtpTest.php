<?php

namespace Tests\Feature;

use App\Models\OtpRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SendOtpTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_send_otp_stores_otp_and_sends_email(): void
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

        $this->postJson('/api/auth/send-otp', [
            'email' => 'Person@Example.com',
            'purpose' => 'login',
        ])
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'code' => 'OTP_SENT',
                'message' => 'OTP sent successfully.',
            ])
            ->assertDontSee('otp_hash');

        $record = OtpRecord::query()->first();

        $this->assertNotNull($record);
        $this->assertSame('person@example.com', $record->email);
        $this->assertSame('login', $record->purpose);
        $this->assertNull($record->used_at);
        $this->assertTrue($record->expires_at->greaterThan(now()));

        Http::assertSent(function ($request) use ($record): bool {
            return $request->url() === 'https://api.yourgoodtime.in/api/internal/mail/send-otp'
                && $request->hasHeader('X-Mail-Gateway-Key', 'configured-test-key')
                && $request['email'] === 'person@example.com'
                && $request['purpose'] === 'login'
                && $request['expires_minutes'] === 5
                && preg_match('/^\d{6}$/', $request['otp']) === 1
                && Hash::check($request['otp'], $record->otp_hash);
        });
    }

    public function test_public_send_otp_defaults_to_login_purpose(): void
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

        $this->postJson('/api/auth/send-otp', [
            'email' => 'person@example.com',
        ])->assertOk();

        $this->assertDatabaseHas('otp_records', [
            'email' => 'person@example.com',
            'purpose' => 'login',
        ]);

        Http::assertSent(function ($request): bool {
            return $request['purpose'] === 'login';
        });
    }

    public function test_signup_otp_for_new_email_returns_registration_next_action(): void
    {
        config([
            'services.mail_gateway.url' => 'https://api.yourgoodtime.in/api/internal/mail/send-otp',
            'services.mail_gateway.key' => 'configured-test-key',
        ]);

        Http::fake([
            'https://api.yourgoodtime.in/api/internal/mail/send-otp' => Http::response(['success' => true]),
        ]);

        $this->postJson('/api/auth/send-otp', [
            'email' => 'new@example.com',
            'purpose' => 'signup',
            'name' => 'New User',
        ])
            ->assertOk()
            ->assertJsonPath('next_action', 'VERIFY_REGISTRATION_OTP');

        $this->assertDatabaseHas('otp_records', [
            'email' => 'new@example.com',
            'purpose' => 'signup',
        ]);
    }

    public function test_signup_otp_for_existing_email_returns_registered_error_without_sending_otp(): void
    {
        config([
            'services.mail_gateway.url' => 'https://api.yourgoodtime.in/api/internal/mail/send-otp',
            'services.mail_gateway.key' => 'configured-test-key',
        ]);
        User::factory()->create(['email' => 'customer@example.com']);

        Http::fake([
            'https://api.yourgoodtime.in/api/internal/mail/send-otp' => Http::response(['success' => true]),
        ]);

        $this->postJson('/api/auth/send-otp', [
            'email' => 'Customer@Example.com',
            'purpose' => 'signup',
        ])
            ->assertStatus(409)
            ->assertJson([
                'success' => false,
                'code' => 'EMAIL_ALREADY_REGISTERED',
                'message' => 'This email is already registered. Please log in to continue.',
            ]);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('otp_records', 0);
        Http::assertNothingSent();
    }

    public function test_public_send_otp_validates_input(): void
    {
        Http::fake();

        $this->postJson('/api/auth/send-otp', [
            'email' => 'not-an-email',
            'purpose' => 'billing',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'purpose']);

        $this->assertDatabaseCount('otp_records', 0);
        Http::assertNothingSent();
    }

    public function test_public_send_otp_returns_error_when_gateway_fails(): void
    {
        config([
            'services.mail_gateway.url' => 'https://api.yourgoodtime.in/api/internal/mail/send-otp',
            'services.mail_gateway.key' => 'configured-test-key',
        ]);

        Http::fake([
            'https://api.yourgoodtime.in/api/internal/mail/send-otp' => Http::response([
                'success' => false,
                'message' => 'Unauthorized request.',
            ], 401),
        ]);

        $this->postJson('/api/auth/send-otp', [
            'email' => 'person@example.com',
            'purpose' => 'login',
        ])
            ->assertStatus(502)
            ->assertJson([
                'success' => false,
                'message' => 'Unable to send OTP email through the mail gateway.',
                'gateway_status' => 401,
            ]);

        $this->assertDatabaseCount('otp_records', 0);
    }
}
