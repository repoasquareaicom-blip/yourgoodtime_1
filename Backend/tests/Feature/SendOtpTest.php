<?php

namespace Tests\Feature;

use App\Mail\OtpMail;
use App\Models\OtpRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SendOtpTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_send_otp_uses_default_mailer_when_local_mailer_enabled(): void
    {
        $this->configureLocalMailer();
        Http::fake();
        Mail::fake();

        $this->postJson('/api/auth/send-otp', [
            'email' => 'Person@Example.com',
            'purpose' => 'login',
        ])
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'code' => 'OTP_SENT',
                'message' => 'OTP sent successfully.',
            ]);

        Http::assertNothingSent();
        Mail::assertSent(OtpMail::class, 1);
        Mail::assertSent(OtpMail::class, function (OtpMail $mail): bool {
            return $mail->hasTo('person@example.com')
                && $mail->mailer !== 'zeptomail'
                && $mail->purpose === 'login'
                && $mail->expiresMinutes === 5;
        });
        Mail::assertNotQueued(OtpMail::class);

        $this->assertDatabaseHas('otp_records', [
            'email' => 'person@example.com',
            'purpose' => 'login',
        ]);
    }

    public function test_public_send_otp_rejects_invalid_mail_system(): void
    {
        config([
            'services.otp_mail.system' => 'invalid-provider',
            'services.mail_gateway.url' => 'https://api.yourgoodtime.in/api/internal/mail/send-otp',
            'services.zeptomail_api.url' => 'https://api.zeptomail.in/v1.1/email',
            'services.zeptomail_api.token' => 'test-zepto-token',
        ]);
        Http::fake();
        Mail::fake();

        $this->postJson('/api/auth/send-otp', [
            'email' => 'person@example.com',
            'purpose' => 'login',
        ])
            ->assertStatus(502)
            ->assertExactJson([
                'success' => false,
                'message' => 'Unable to send OTP at this time.',
            ])
            ->assertDontSee('invalid-provider')
            ->assertDontSee('test-zepto-token');

        $this->assertDatabaseCount('otp_records', 0);
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_public_send_otp_stores_otp_and_sends_email(): void
    {
        $this->configureZeptoMailApi();
        Mail::fake();
        $this->fakeZeptoMailApi();

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

        Mail::assertNothingSent();
        $this->assertZeptoMailApiSent('person@example.com', 'login', 'Your Login OTP - Your Good Time', $record);
        $this->assertOldMailGatewayNotCalled();
    }

    public function test_public_send_otp_defaults_to_login_purpose(): void
    {
        $this->configureZeptoMailApi();
        Mail::fake();
        $this->fakeZeptoMailApi();

        $this->postJson('/api/auth/send-otp', [
            'email' => 'person@example.com',
        ])->assertOk();

        $this->assertDatabaseHas('otp_records', [
            'email' => 'person@example.com',
            'purpose' => 'login',
        ]);

        Http::assertSent(function ($request): bool {
            $payload = $this->requestPayload($request);

            return $request->url() === 'https://api.zeptomail.in/v1.1/email'
                && ($payload['subject'] ?? null) === 'Your Login OTP - Your Good Time';
        });
        $this->assertOldMailGatewayNotCalled();
    }

    public function test_signup_otp_for_new_email_returns_registration_next_action(): void
    {
        $this->configureZeptoMailApi();
        Mail::fake();
        $this->fakeZeptoMailApi();

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

        Http::assertSent(function ($request): bool {
            $payload = $this->requestPayload($request);

            return $request->url() === 'https://api.zeptomail.in/v1.1/email'
                && ($payload['subject'] ?? null) === 'Verify Your Account - Your Good Time';
        });
    }

    public function test_signup_otp_for_existing_email_returns_registered_error_without_sending_otp(): void
    {
        $this->configureZeptoMailApi();
        User::factory()->create(['email' => 'customer@example.com']);

        Http::fake();
        Mail::fake();

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
        Mail::assertNothingSent();
    }

    public function test_public_send_otp_validates_input(): void
    {
        Http::fake();
        Mail::fake();

        $this->postJson('/api/auth/send-otp', [
            'email' => 'not-an-email',
            'purpose' => 'billing',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'purpose']);

        $this->assertDatabaseCount('otp_records', 0);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_public_send_otp_returns_error_when_zeptomail_api_fails(): void
    {
        $this->configureZeptoMailApi();
        Mail::fake();
        $this->fakeZeptoMailApi(Http::response([
            'error' => [
                'code' => 'TM_4001',
                'message' => 'Invalid send mail request.',
            ],
        ], 400));

        $this->postJson('/api/auth/send-otp', [
            'email' => 'person@example.com',
            'purpose' => 'login',
        ])
            ->assertStatus(502)
            ->assertExactJson([
                'success' => false,
                'message' => 'Unable to send OTP at this time.',
            ])
            ->assertDontSee('test-zepto-token')
            ->assertDontSee('Zoho-enczapikey')
            ->assertDontSee('otp_hash');

        $this->assertDatabaseCount('otp_records', 0);
        Mail::assertNothingSent();
        $this->assertOldMailGatewayNotCalled();
    }

    public function test_public_send_otp_returns_error_when_zeptomail_api_returns_server_error(): void
    {
        $this->configureZeptoMailApi();
        Mail::fake();
        $this->fakeZeptoMailApi(Http::response([
            'error' => [
                'code' => 'SM_101',
                'message' => 'Internal error.',
            ],
        ], 500));

        $this->postJson('/api/auth/send-otp', [
            'email' => 'person@example.com',
            'purpose' => 'login',
        ])
            ->assertStatus(502)
            ->assertJson([
                'success' => false,
                'message' => 'Unable to send OTP at this time.',
            ]);

        $this->assertDatabaseCount('otp_records', 0);
        Mail::assertNothingSent();
    }

    public function test_public_send_otp_returns_error_when_zeptomail_connection_fails(): void
    {
        $this->configureZeptoMailApi();
        Mail::fake();
        Http::fake(function () {
            throw new ConnectionException('Connection timed out');
        });

        $this->postJson('/api/auth/send-otp', [
            'email' => 'person@example.com',
            'purpose' => 'login',
        ])
            ->assertStatus(502)
            ->assertExactJson([
                'success' => false,
                'message' => 'Unable to send OTP at this time.',
            ]);

        $this->assertDatabaseCount('otp_records', 0);
        Mail::assertNothingSent();
    }

    public function test_zeptomail_authorization_adds_prefix_when_missing(): void
    {
        $this->configureZeptoMailApi('raw-zepto-token');
        Mail::fake();
        $this->fakeZeptoMailApi();

        $this->postJson('/api/auth/send-otp', [
            'email' => 'person@example.com',
            'purpose' => 'login',
        ])->assertOk();

        Http::assertSent(function ($request): bool {
            $authorization = $request->header('Authorization')[0] ?? '';

            return $request->url() === 'https://api.zeptomail.in/v1.1/email'
                && $authorization === 'Zoho-enczapikey raw-zepto-token';
        });
    }

    public function test_zeptomail_authorization_does_not_duplicate_existing_prefix(): void
    {
        $this->configureZeptoMailApi('Zoho-enczapikey already-prefixed-token');
        Mail::fake();
        $this->fakeZeptoMailApi();

        $this->postJson('/api/auth/send-otp', [
            'email' => 'person@example.com',
            'purpose' => 'login',
        ])->assertOk();

        Http::assertSent(function ($request): bool {
            $authorization = $request->header('Authorization')[0] ?? '';

            return $request->url() === 'https://api.zeptomail.in/v1.1/email'
                && $authorization === 'Zoho-enczapikey already-prefixed-token'
                && ! str_contains($authorization, 'Zoho-enczapikey Zoho-enczapikey');
        });
    }

    public function test_zeptomail_failure_logs_do_not_include_secrets(): void
    {
        $this->configureZeptoMailApi('secret-zepto-token');
        Mail::fake();
        Log::spy();
        $this->fakeZeptoMailApi(Http::response([
            'error' => [
                'code' => 'TM_4001',
                'message' => 'Invalid send mail request.',
            ],
        ], 400));

        $this->postJson('/api/auth/send-otp', [
            'email' => 'person@example.com',
            'purpose' => 'login',
        ])->assertStatus(502);

        Log::shouldHaveReceived('error')->withArgs(function (string $message, array $context): bool {
            $encoded = json_encode($context);

            return $message === 'OTP ZeptoMail API send failed.'
                && ($context['email'] ?? null) === 'pe***@example.com'
                && ($context['purpose'] ?? null) === 'login'
                && ($context['status'] ?? null) === 400
                && ($context['zeptomail_error_code'] ?? null) === 'TM_4001'
                && is_string($encoded)
                && ! str_contains($encoded, 'secret-zepto-token')
                && ! str_contains($encoded, 'Zoho-enczapikey')
                && ! str_contains($encoded, 'htmlbody')
                && ! str_contains($encoded, 'textbody');
        });
    }

    private function configureLocalMailer(): void
    {
        config([
            'services.mail_gateway.url' => 'https://api.yourgoodtime.in/api/internal/mail/send-otp',
            'services.mail_gateway.key' => 'configured-test-key',
            'services.otp_mail.system' => 'local',
        ]);
    }

    private function configureZeptoMailApi(string $token = 'test-zepto-token'): void
    {
        config([
            'services.mail_gateway.url' => 'https://api.yourgoodtime.in/api/internal/mail/send-otp',
            'services.mail_gateway.key' => 'configured-test-key',
            'services.otp_mail.system' => 'zeptoapi',
            'services.zeptomail_api.url' => 'https://api.zeptomail.in/v1.1/email',
            'services.zeptomail_api.token' => $token,
            'services.zeptomail_api.from_address' => 'support@yourgoodtime.in',
            'services.zeptomail_api.from_name' => 'Your Good Time',
            'services.zeptomail_api.connect_timeout' => 5,
            'services.zeptomail_api.timeout' => 10,
        ]);
    }

    private function fakeZeptoMailApi($response = null): void
    {
        Http::fake([
            'https://api.zeptomail.in/v1.1/email' => $response ?? Http::response([
                'data' => [
                    [
                        'code' => 'EM_104',
                        'message' => 'Email request received',
                    ],
                ],
                'message' => 'OK',
            ], 200),
            'https://api.yourgoodtime.in/*' => Http::response(['unexpected' => true], 500),
        ]);
    }

    private function assertZeptoMailApiSent(string $email, string $purpose, string $subject, OtpRecord $record): void
    {
        Http::assertSent(function ($request) use ($email, $purpose, $subject, $record): bool {
            $payload = $this->requestPayload($request);
            $authorization = $request->header('Authorization')[0] ?? '';
            $html = (string) ($payload['htmlbody'] ?? '');
            $text = (string) ($payload['textbody'] ?? '');
            preg_match('/OTP:\s*(\d{6})/', $text, $matches);
            $otp = $matches[1] ?? null;

            return $request->url() === 'https://api.zeptomail.in/v1.1/email'
                && str_starts_with($authorization, 'Zoho-enczapikey ')
                && data_get($payload, 'from.address') === 'support@yourgoodtime.in'
                && data_get($payload, 'from.name') === 'Your Good Time'
                && data_get($payload, 'to.0.email_address.address') === $email
                && data_get($payload, 'subject') === $subject
                && $html !== ''
                && $text !== ''
                && str_contains($html, 'Your Good Time')
                && is_string($otp)
                && Hash::check($otp, $record->otp_hash)
                && $purpose === $record->purpose;
        });
    }

    private function assertOldMailGatewayNotCalled(): void
    {
        Http::assertNotSent(function ($request): bool {
            return str_contains($request->url(), 'api.yourgoodtime.in/api/internal/mail');
        });
    }

    private function requestPayload($request): array
    {
        $payload = $request->data();

        if (is_array($payload) && $payload !== []) {
            return $payload;
        }

        $decoded = json_decode($request->body(), true);

        return is_array($decoded) ? $decoded : [];
    }
}
