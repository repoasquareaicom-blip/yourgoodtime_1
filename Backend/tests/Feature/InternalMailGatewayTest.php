<?php

namespace Tests\Feature;

use App\Mail\GatewayMail;
use App\Mail\OtpMail;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class InternalMailGatewayTest extends TestCase
{
    private const GATEWAY_KEY = 'configured-test-key';

    public function test_zeptomail_mailer_is_configured_for_otp_delivery(): void
    {
        $mailer = config('mail.mailers.zeptomail');

        $this->assertSame('smtp', $mailer['transport']);
        $this->assertSame('smtp', $mailer['scheme']);
        $this->assertSame('smtp.zeptomail.in', $mailer['host']);
        $this->assertSame(587, (int) $mailer['port']);
        $this->assertSame('emailapikey', $mailer['username']);
        $this->assertTrue($mailer['auto_tls']);
        $this->assertSame('support@yourgoodtime.in', $mailer['from']['address']);
        $this->assertSame('Your Good Time', $mailer['from']['name']);
    }

    public function test_missing_gateway_key_returns_unauthorized(): void
    {
        config(['services.mail_gateway.key' => self::GATEWAY_KEY]);
        Mail::fake();

        $this->postJson('/api/internal/mail/send-otp', $this->payload())
            ->assertUnauthorized()
            ->assertExactJson([
                'success' => false,
                'message' => 'Unauthorized request.',
            ]);

        Mail::assertNothingSent();
    }

    public function test_incorrect_gateway_key_returns_unauthorized(): void
    {
        config(['services.mail_gateway.key' => self::GATEWAY_KEY]);
        Mail::fake();

        $this->postJson('/api/internal/mail/send-otp', $this->payload(), [
            'X-Mail-Gateway-Key' => 'wrong-key',
        ])
            ->assertUnauthorized()
            ->assertExactJson([
                'success' => false,
                'message' => 'Unauthorized request.',
            ]);

        Mail::assertNothingSent();
    }

    public function test_absent_configured_gateway_key_returns_unauthorized(): void
    {
        config(['services.mail_gateway.key' => null]);
        Mail::fake();

        $this->postJson('/api/internal/mail/send-otp', $this->payload(), [
            'X-Mail-Gateway-Key' => self::GATEWAY_KEY,
        ])
            ->assertUnauthorized()
            ->assertExactJson([
                'success' => false,
                'message' => 'Unauthorized request.',
            ]);

        Mail::assertNothingSent();
    }

    public function test_correct_gateway_key_allows_valid_request_and_sends_one_mailable(): void
    {
        $this->configureGateway();
        Mail::fake();

        $response = $this->postJson('/api/internal/mail/send-otp', $this->payload([
            'email' => 'person@example.com',
            'otp' => '123456',
            'purpose' => 'login',
        ]), $this->headers());

        $response
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'message' => 'OTP email sent successfully.',
            ])
            ->assertDontSee('123456')
            ->assertDontSee('smtp');

        Mail::assertSent(OtpMail::class, 1);
        Mail::assertSent(OtpMail::class, function (OtpMail $mail): bool {
            return $mail->hasTo('person@example.com')
                && $mail->mailer !== 'zeptomail'
                && $mail->otp === '123456'
                && $mail->purpose === 'login'
                && $mail->expiresMinutes === 5;
        });
        Mail::assertNotQueued(OtpMail::class);
    }

    public function test_correct_gateway_key_sends_transactional_mail(): void
    {
        $this->configureGateway();
        Mail::fake();

        $this->postJson('/api/internal/mail/send', [
            'to' => 'client@example.com',
            'subject' => 'Thank you for contacting Your Good Time',
            'html' => '<p>We received your query.</p>',
            'text' => 'We received your query.',
            'reply_to' => 'support@yourgoodtime.in',
        ], $this->headers())
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'message' => 'Email sent successfully.',
            ]);

        Mail::assertSent(GatewayMail::class, function (GatewayMail $mail): bool {
            return $mail->hasTo('client@example.com')
                && $mail->htmlBody === '<p>We received your query.</p>'
                && $mail->replyToAddress === 'support@yourgoodtime.in';
        });
    }

    public function test_invalid_email_returns_validation_error(): void
    {
        $this->configureGateway();
        Mail::fake();

        $this->postJson('/api/internal/mail/send-otp', $this->payload([
            'email' => 'not-an-email',
        ]), $this->headers())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        Mail::assertNothingSent();
    }

    public function test_invalid_otp_format_returns_validation_error(): void
    {
        $this->configureGateway();
        Mail::fake();

        $this->postJson('/api/internal/mail/send-otp', $this->payload([
            'otp' => '12345a',
        ]), $this->headers())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('otp');

        Mail::assertNothingSent();
    }

    public function test_invalid_purpose_returns_validation_error(): void
    {
        $this->configureGateway();
        Mail::fake();

        $this->postJson('/api/internal/mail/send-otp', $this->payload([
            'purpose' => 'billing',
        ]), $this->headers())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('purpose');

        Mail::assertNothingSent();
    }

    public function test_otp_mail_uses_correct_purpose_content(): void
    {
        $this->configureGateway();
        Mail::fake();

        $this->postJson('/api/internal/mail/send-otp', $this->payload([
            'email' => 'verify@example.com',
            'otp' => '654321',
            'purpose' => 'password_reset',
        ]), $this->headers())->assertOk();

        Mail::assertSent(OtpMail::class, function (OtpMail $mail): bool {
            return $mail->hasTo('verify@example.com')
                && $mail->purpose === 'password_reset'
                && $mail->messageText === 'Use this OTP to continue resetting your password.';
        });
    }

    public function test_recipient_rate_limit_returns_429_when_exceeded(): void
    {
        $this->configureGateway();
        Mail::fake();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/internal/mail/send-otp', $this->payload([
                'otp' => (string) (111111 + $attempt),
            ]), $this->headers())->assertOk();
        }

        $this->postJson('/api/internal/mail/send-otp', $this->payload([
            'otp' => '222222',
        ]), $this->headers())->assertTooManyRequests();
    }

    private function configureGateway(): void
    {
        config(['services.mail_gateway.key' => self::GATEWAY_KEY]);
    }

    private function headers(): array
    {
        return [
            'X-Mail-Gateway-Key' => self::GATEWAY_KEY,
        ];
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'email' => 'test@example.com',
            'otp' => '123456',
            'expires_minutes' => 5,
            'purpose' => 'login',
        ], $overrides);
    }
}
