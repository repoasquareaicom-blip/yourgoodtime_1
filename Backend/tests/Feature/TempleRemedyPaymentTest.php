<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\PaymentTransaction;
use App\Models\PredictionRequest;
use App\Models\TempleRemedyRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TempleRemedyPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.razorpay.key_id' => null,
            'services.razorpay.key_secret' => null,
        ]);
    }

    public function test_remedy_details_are_hidden_until_required_payment_is_completed(): void
    {
        $user = User::factory()->create();
        $remedy = $this->templeRemedy($user, [
            'status' => 'PAYMENT_PENDING',
            'payment_enabled' => true,
            'amount_minor' => 150000,
            'payment_status' => 'PENDING',
            'remedy_type' => 'pdf',
            'remedy_title' => 'Temple Visit Guidance',
            'remedy_url' => 'https://example.com/remedy.pdf',
            'admin_note' => 'Visit on Friday morning.',
            'remedy_uploaded_at' => now(),
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/temple-remedies')
            ->assertOk()
            ->assertJsonPath('data.0.id', $remedy->id)
            ->assertJsonPath('data.0.remedy.available', false)
            ->assertJsonPath('data.0.remedy.url', null)
            ->assertJsonPath('data.0.admin_note', null);
    }

    public function test_successful_temple_remedy_payment_reveals_remedy(): void
    {
        $user = User::factory()->create();
        $remedy = $this->templeRemedy($user, [
            'status' => 'PAYMENT_PENDING',
            'payment_enabled' => true,
            'amount_minor' => 150000,
            'payment_status' => 'PENDING',
            'remedy_type' => 'pdf',
            'remedy_title' => 'Temple Visit Guidance',
            'remedy_url' => 'https://example.com/remedy.pdf',
            'admin_note' => 'Visit on Friday morning.',
            'remedy_uploaded_at' => now(),
        ]);

        $orderId = $this->actingAs($user, 'sanctum')
            ->postJson('/api/payments/temple-remedy/order', [
                'temple_remedy_request_id' => $remedy->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.remedy.amount_minor', 150000)
            ->json('data.razorpay_order_id');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/payments/razorpay/verify', [
                'razorpay_order_id' => $orderId,
                'razorpay_payment_id' => 'pay_remedy_test_123',
                'razorpay_signature' => 'test_signature',
            ])
            ->assertOk()
            ->assertJsonPath('temple_remedy_paid', true)
            ->assertJsonPath('temple_remedy_request_id', $remedy->id);

        $this->assertDatabaseHas('payment_transactions', [
            'provider_order_id' => $orderId,
            'provider_payment_id' => 'pay_remedy_test_123',
            'temple_remedy_request_id' => $remedy->id,
            'user_id' => $user->id,
            'status' => 'CAPTURED',
        ]);

        $this->assertDatabaseHas('temple_remedy_requests', [
            'id' => $remedy->id,
            'payment_status' => 'PAID',
            'status' => 'COMPLETED',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/temple-remedies')
            ->assertOk()
            ->assertJsonPath('data.0.remedy.available', true)
            ->assertJsonPath('data.0.remedy.url', url("/api/temple-remedies/{$remedy->id}/file"))
            ->assertJsonPath('data.0.admin_note', 'Visit on Friday morning.');
    }

    public function test_failed_temple_remedy_verification_does_not_complete_request(): void
    {
        config(['services.razorpay.key_secret' => 'secret']);
        $user = User::factory()->create();
        $remedy = $this->templeRemedy($user, [
            'status' => 'PAYMENT_PENDING',
            'payment_enabled' => true,
            'amount_minor' => 150000,
            'payment_status' => 'PENDING',
            'remedy_type' => 'pdf',
            'remedy_title' => 'Temple Visit Guidance',
            'remedy_url' => 'https://example.com/remedy.pdf',
            'admin_note' => 'Visit on Friday morning.',
            'remedy_uploaded_at' => now(),
        ]);

        $orderId = $this->actingAs($user, 'sanctum')
            ->postJson('/api/payments/temple-remedy/order', [
                'temple_remedy_request_id' => $remedy->id,
            ])
            ->assertOk()
            ->json('data.razorpay_order_id');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/payments/razorpay/verify', [
                'razorpay_order_id' => $orderId,
                'razorpay_payment_id' => 'pay_failed_test_123',
                'razorpay_signature' => 'bad_signature',
            ])
            ->assertUnprocessable();

        $this->assertDatabaseHas('temple_remedy_requests', [
            'id' => $remedy->id,
            'payment_status' => 'PENDING',
            'status' => 'PAYMENT_PENDING',
        ]);
    }

    public function test_user_cannot_create_order_for_another_users_remedy(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $remedy = $this->templeRemedy($owner, [
            'status' => 'PAYMENT_PENDING',
            'payment_enabled' => true,
            'amount_minor' => 150000,
            'payment_status' => 'PENDING',
        ]);

        $this->actingAs($otherUser, 'sanctum')
            ->postJson('/api/payments/temple-remedy/order', [
                'temple_remedy_request_id' => $remedy->id,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('payment_transactions', 0);
    }

    public function test_admin_can_set_inr_amount_and_upload_remedy_file(): void
    {
        Storage::fake('local');

        $admin = AdminUser::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('secret123'),
            'is_active' => true,
        ]);
        $user = User::factory()->create();
        $remedy = $this->templeRemedy($user);

        $this->actingAs($admin, 'sanctum')
            ->post("/api/admin/temple-remedies/{$remedy->id}", [
                '_method' => 'PATCH',
                'status' => 'PAYMENT_PENDING',
                'amount_inr' => 600,
                'currency' => 'INR',
                'remedy_title' => 'Temple Visit Guidance',
                'remedy_file' => UploadedFile::fake()->create('remedy.pdf', 120, 'application/pdf'),
                'admin_note' => 'Visit on Friday morning.',
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.amount_minor', 60000)
            ->assertJsonPath('data.payment_status', 'PENDING')
            ->assertJsonPath('data.remedy_type', 'pdf');

        $remedy->refresh();

        $this->assertSame(60000, $remedy->amount_minor);
        $this->assertSame('PAYMENT_PENDING', $remedy->status);
        $this->assertNotNull($remedy->remedy_file_path);
        $this->assertNull($remedy->remedy_url);
        Storage::disk('local')->assertExists($remedy->remedy_file_path);
    }

    public function test_admin_can_manually_complete_with_required_remedy_data(): void
    {
        Storage::fake('local');

        $admin = AdminUser::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('secret123'),
            'is_active' => true,
        ]);
        $user = User::factory()->create();
        $remedy = $this->templeRemedy($user);

        $this->actingAs($admin, 'sanctum')
            ->post("/api/admin/temple-remedies/{$remedy->id}", [
                '_method' => 'PATCH',
                'status' => 'COMPLETED',
                'remedy_title' => 'Free Temple Guidance',
                'remedy_file' => UploadedFile::fake()->image('remedy.png'),
                'admin_note' => 'Please follow this guidance.',
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED')
            ->assertJsonPath('data.payment_status', 'NOT_REQUIRED')
            ->assertJsonPath('data.completion_method', 'manual');

        $this->assertDatabaseHas('temple_remedy_requests', [
            'id' => $remedy->id,
            'status' => 'COMPLETED',
            'completion_method' => 'manual',
            'completed_by_admin_id' => $admin->id,
        ]);
    }

    public function test_completed_request_cannot_move_backward(): void
    {
        $admin = AdminUser::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('secret123'),
            'is_active' => true,
        ]);
        $user = User::factory()->create();
        $remedy = $this->templeRemedy($user, [
            'status' => 'COMPLETED',
            'payment_status' => 'NOT_REQUIRED',
            'completion_method' => 'manual',
            'completed_at' => now(),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/temple-remedies/{$remedy->id}", [
                'status' => 'REVIEWING',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This request has already been completed.');
    }

    public function test_admin_temple_remedy_groups_return_expected_records_and_counts(): void
    {
        $admin = AdminUser::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('secret123'),
            'is_active' => true,
        ]);
        $user = User::factory()->create();
        $received = $this->templeRemedy($user, ['status' => 'RECEIVED']);
        $reviewing = $this->templeRemedy($user, ['status' => 'REVIEWING']);
        $paymentPending = $this->templeRemedy($user, ['status' => 'PAYMENT_PENDING']);
        $completed = $this->templeRemedy($user, ['status' => 'COMPLETED']);

        $pendingResponse = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/temple-remedies?group=pending')
            ->assertOk()
            ->assertJsonPath('counts.pending', 3)
            ->assertJsonPath('counts.completed', 1);

        $this->assertEqualsCanonicalizing(
            [$received->id, $reviewing->id, $paymentPending->id],
            collect($pendingResponse->json('data'))->pluck('id')->all()
        );

        $completedResponse = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/temple-remedies?group=completed')
            ->assertOk()
            ->assertJsonPath('counts.pending', 3)
            ->assertJsonPath('counts.completed', 1);

        $this->assertSame([$completed->id], collect($completedResponse->json('data'))->pluck('id')->all());
    }

    public function test_completed_request_can_update_title_and_notes_while_retaining_file_and_payment_metadata(): void
    {
        $admin = AdminUser::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('secret123'),
            'is_active' => true,
        ]);
        $user = User::factory()->create();
        $paidAt = now()->subHour();
        $completedAt = now()->subMinutes(30);
        $remedy = $this->templeRemedy($user, [
            'status' => 'COMPLETED',
            'amount_minor' => 60000,
            'payment_status' => 'PAID',
            'paid_at' => $paidAt,
            'completed_at' => $completedAt,
            'completion_method' => 'payment',
            'remedy_title' => 'Old title',
            'remedy_file_path' => 'temple-remedies/existing.pdf',
            'remedy_file_name' => 'existing.pdf',
            'remedy_mime_type' => 'application/pdf',
            'remedy_file_size' => 1234,
            'admin_note' => 'Old note',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/temple-remedies/{$remedy->id}", [
                'status' => 'COMPLETED',
                'remedy_title' => 'Updated title',
                'admin_note' => 'Updated note',
            ])
            ->assertOk()
            ->assertJsonPath('data.remedy_title', 'Updated title')
            ->assertJsonPath('data.admin_note', 'Updated note');

        $remedy->refresh();

        $this->assertSame('temple-remedies/existing.pdf', $remedy->remedy_file_path);
        $this->assertSame('existing.pdf', $remedy->remedy_file_name);
        $this->assertSame('PAID', $remedy->payment_status);
        $this->assertSame('payment', $remedy->completion_method);
        $this->assertSame($paidAt->toDateTimeString(), $remedy->paid_at->toDateTimeString());
        $this->assertSame($completedAt->toDateTimeString(), $remedy->completed_at->toDateTimeString());
    }

    public function test_admin_astrology_endpoint_requires_admin_authentication(): void
    {
        $user = User::factory()->create();
        $remedy = $this->templeRemedy($user);

        $this->getJson("/api/admin/temple-remedies/{$remedy->id}/astrology")
            ->assertUnauthorized();
    }

    public function test_admin_astrology_endpoint_returns_stored_prediction_data_for_linked_request(): void
    {
        $admin = AdminUser::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('secret123'),
            'is_active' => true,
        ]);
        $user = User::factory()->create(['email' => 'owner@example.com']);
        $predictionRequest = $this->predictionRequest($user);
        $remedy = $this->templeRemedy($user, ['prediction_request_id' => $predictionRequest->id]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/temple-remedies/{$remedy->id}/astrology")
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('prediction_request_id', $predictionRequest->id)
            ->assertJsonPath('data.identity.rasi', 'Aries')
            ->assertJsonPath('data.identity.nakshatra', 'Bharani');

        $viewerUrl = $response->json('viewer_url');
        $this->assertStringStartsWith(url('/view-prediction-response.php').'?id='.$predictionRequest->id.'&embedded=1', $viewerUrl);
        $this->assertStringContainsString('&expires=', $viewerUrl);
        $this->assertStringContainsString('&signature=', $viewerUrl);
    }

    public function test_admin_astrology_endpoint_rejects_unrelated_prediction_request_link(): void
    {
        $admin = AdminUser::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('secret123'),
            'is_active' => true,
        ]);
        $owner = User::factory()->create(['email' => 'owner@example.com']);
        $otherUser = User::factory()->create(['email' => 'other@example.com']);
        $otherPrediction = $this->predictionRequest($otherUser);
        $remedy = $this->templeRemedy($owner, ['prediction_request_id' => $otherPrediction->id]);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/temple-remedies/{$remedy->id}/astrology")
            ->assertOk()
            ->assertJsonPath('available', false)
            ->assertJsonPath('message', 'Astrology data is not available for this request.');
    }

    private function templeRemedy(User $user, array $overrides = []): TempleRemedyRequest
    {
        return TempleRemedyRequest::query()->create(array_merge([
            'user_id' => $user->id,
            'problem_text' => 'I am facing repeated delays and need a suitable temple remedy.',
            'status' => 'RECEIVED',
            'payment_enabled' => false,
            'amount_minor' => null,
            'currency' => 'INR',
            'payment_status' => 'NOT_REQUIRED',
            'submitted_at' => now(),
        ], $overrides));
    }

    private function predictionRequest(User $user, array $overrides = []): PredictionRequest
    {
        return PredictionRequest::query()->create(array_merge([
            'request_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'chart_fingerprint' => hash('sha256', $user->email),
            'prediction_type' => 'past',
            'status' => 'completed',
            'full_name' => $user->name,
            'date_of_birth' => '1990-01-01',
            'time_of_birth' => '10:30',
            'timezone' => 'Asia/Kolkata',
            'place_of_birth' => 'Chennai',
            'country' => 'India',
            'country_code' => 'IN',
            'latitude' => 13.0827,
            'longitude' => 80.2707,
            'marriage_status' => 'married',
            'email' => $user->email,
            'birth_payload' => ['day' => 1],
            'raw_astrology_response' => ['provider' => 'stored'],
            'normalized_astrology_data' => [
                'value' => [
                    'birth' => ['date' => '1990-01-01', 'time' => '10:30'],
                    'identity' => ['rasi' => 'Aries', 'nakshatra' => 'Bharani', 'lagnam' => 'Leo'],
                    'chart' => ['D1' => 'chart-data'],
                    'planets' => [['name' => 'Sun', 'sign' => 'Aries']],
                    'dasha' => ['major' => [['planet' => 'Venus', 'start' => '2020']]],
                ],
            ],
            'submitted_at' => now(),
            'completed_at' => now(),
        ], $overrides));
    }
}
