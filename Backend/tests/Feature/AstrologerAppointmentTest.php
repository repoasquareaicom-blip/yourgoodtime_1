<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\AppointmentPaymentMethod;
use App\Models\Astrologer;
use App\Models\AstrologerAppointment;
use App\Models\AstrologerAppointmentSlotBlock;
use App\Models\AstrologerConsultationQuestion;
use App\Models\PredictionProduct;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class AstrologerAppointmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config([
            'services.razorpay.key_id' => null,
            'services.razorpay.key_secret' => null,
        ]);

        PredictionProduct::query()->updateOrCreate(
            ['code' => 'ASTROLOGER_CONSULTATION'],
            [
                'name' => 'Talk to Astrologer',
                'description' => 'Direct online consultation.',
                'prediction_type' => 'appointment',
                'price_inclusive_tax_minor' => 600000,
                'currency' => 'INR',
                'tax_inclusive' => true,
                'is_active' => true,
                'metadata' => [
                    'maximum_questions' => 3,
                ],
            ]
        );
    }

    public function test_user_can_create_pending_appointment_from_backend_config(): void
    {
        $user = User::factory()->create();

        $this->getJson('/api/astrologer-appointments/config')
            ->assertOk()
            ->assertJsonPath('data.amount_minor', 600000)
            ->assertJsonPath('data.duration_minutes', 20)
            ->assertJsonPath('data.maximum_questions', 3)
            ->assertJsonCount(2, 'data.languages')
            ->assertJsonCount(2, 'data.payment_methods')
            ->assertJsonCount(10, 'data.questions');

        $questionIds = AstrologerConsultationQuestion::query()
            ->orderBy('sort_order')
            ->limit(2)
            ->pluck('id')
            ->all();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/astrologer-appointments', [
                'question_ids' => $questionIds,
                'notes' => 'Prefer morning consultation.',
                'preferred_date' => now('Asia/Kolkata')->addDays(3)->toDateString(),
                'preferred_time' => '10:30',
                'preferred_timezone' => 'Asia/Kolkata',
                'contact_number' => '+91 98765 43210',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.amount_minor', 600000)
            ->assertJsonPath('data.duration_minutes', 20)
            ->assertJsonPath('data.questions_count', 2)
            ->assertJsonPath('data.contact_number', '+91 98765 43210')
            ->assertJsonPath('data.can_pay', true);

        $this->assertDatabaseHas('astrologer_appointments', [
            'user_id' => $user->id,
            'status' => 'open',
            'amount_minor' => 600000,
            'duration_minutes' => 20,
            'payment_stage' => 'before_admin_action',
            'payment_status' => 'PENDING',
        ]);
        $this->assertDatabaseCount('astrologer_appointment_questions', 2);
        $this->assertDatabaseHas('astrologer_appointment_questions', [
            'consultation_question_id' => $questionIds[0],
            'sequence' => 1,
        ]);
    }

    public function test_user_pays_then_admin_can_fix_slot(): void
    {
        $user = User::factory()->create();
        $admin = AdminUser::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('secret123'),
            'is_active' => true,
        ]);
        $appointment = AstrologerAppointment::query()->create([
            'user_id' => $user->id,
            'appointment_number' => 'YGT-AST-TEST',
            'status' => 'open',
            'preferred_date' => now('Asia/Kolkata')->addDays(2)->toDateString(),
            'preferred_time' => '11:00',
            'preferred_timezone' => 'Asia/Kolkata',
            'duration_minutes' => 30,
            'maximum_questions' => 3,
            'amount_minor' => 600000,
            'currency' => 'INR',
            'tax_inclusive' => true,
            'payment_stage' => 'before_admin_action',
            'payment_status' => 'PENDING',
        ]);
        $appointment->questions()->create(['sequence' => 1, 'question' => 'Will my career improve?']);
        $orderId = $this->actingAs($user, 'sanctum')
            ->postJson('/api/payments/astrologer-appointment/order', [
                'appointment_id' => $appointment->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.appointment.amount_minor', 600000)
            ->json('data.razorpay_order_id');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/payments/razorpay/verify', [
                'razorpay_order_id' => $orderId,
                'razorpay_payment_id' => 'pay_appointment_test_123',
                'razorpay_signature' => 'test_signature',
            ])
            ->assertOk()
            ->assertJsonPath('appointment_paid', true)
            ->assertJsonPath('appointment_id', $appointment->id);

        $this->assertDatabaseHas('astrologer_appointments', [
            'id' => $appointment->id,
            'status' => 'open',
            'payment_status' => 'PAID',
        ]);

        $slotStart = now('Asia/Kolkata')->addDays(2)->setTime(10, 0)->toIso8601String();

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/appointments/{$appointment->id}", [
                'confirmed_start_at' => $slotStart,
                'timezone' => 'Asia/Kolkata',
                'meeting_provider' => 'manual',
                'meeting_link' => 'https://meet.example.com/ygt-test',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'slot_fixed')
            ->assertJsonPath('data.can_pay', false);

        $this->assertDatabaseHas('astrologer_appointments', [
            'id' => $appointment->id,
            'status' => 'slot_fixed',
            'payment_status' => 'PAID',
        ]);
        $this->assertDatabaseHas('payment_transactions', [
            'astrologer_appointment_id' => $appointment->id,
            'user_id' => $user->id,
            'status' => 'CAPTURED',
        ]);
    }

    public function test_admin_slots_include_simple_available_cells_across_day_and_night_rows(): void
    {
        $admin = AdminUser::query()->create([
            'name' => 'Admin',
            'email' => 'admin-slots@example.com',
            'password' => Hash::make('secret123'),
            'is_active' => true,
        ]);
        PredictionProduct::query()
            ->where('code', 'ASTROLOGER_CONSULTATION')
            ->update([
                'metadata' => [
                    'duration_minutes' => 20,
                    'maximum_questions' => 3,
                    'appointment_payment_stage' => 'before_admin_action',
                    'business_timezone' => 'Asia/Kolkata',
                    'slot_duration_minutes' => 20,
                    'working_days' => [1, 2, 3, 4, 5, 6, 7],
                    'working_start_time' => '00:00',
                    'working_end_time' => '00:00',
                    'buffer_minutes' => 0,
                    'break_minutes' => 0,
                    'maximum_advance_days' => 60,
                ],
            ]);
        $astrologer = Astrologer::query()->first();

        $date = now('Asia/Kolkata')->addDays(2)->toDateString();

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/appointments/available-slots?astrologer_id={$astrologer->id}&slot_date={$date}&timezone=America/New_York")
            ->assertOk()
            ->assertJsonPath('data.timezone', 'Asia/Kolkata')
            ->assertJsonPath('data.slot_duration_minutes', 20)
            ->assertJsonPath('data.break_duration_minutes', 0)
            ->assertJsonPath('data.working_hours.start', '00:00')
            ->assertJsonPath('data.working_hours.end', '00:00');

        $slots = $response->json('data.slots');

        $this->assertCount(72, $slots);
        $this->assertSame('12:00 AM', $slots[0]['label']);
        $this->assertSame('12:00 AM - 12:20 AM', $slots[0]['range_label']);
        $this->assertSame('night', $slots[0]['period']);
        $this->assertSame('available', $slots[0]['status']);
        $this->assertSame('12:20 AM - 12:40 AM', $slots[1]['range_label']);
        $this->assertSame('night', $slots[1]['period']);
    }

    public function test_appointment_moves_from_waiting_payment_to_payment_received_after_gateway_payment(): void
    {
        $user = User::factory()->create();
        $admin = AdminUser::query()->create([
            'name' => 'Admin',
            'email' => 'admin-paid-only@example.com',
            'password' => Hash::make('secret123'),
            'is_active' => true,
        ]);
        $questionIds = AstrologerConsultationQuestion::query()
            ->orderBy('sort_order')
            ->limit(1)
            ->pluck('id')
            ->all();

        $appointmentId = $this->actingAs($user, 'sanctum')
            ->postJson('/api/astrologer-appointments', [
                'question_ids' => $questionIds,
                'preferred_date' => now('Asia/Kolkata')->addDays(3)->toDateString(),
                'preferred_time' => '10:30',
                'preferred_timezone' => 'Asia/Kolkata',
                'contact_number' => '+91 98765 43210',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->json('data.id');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/appointments?group=waiting_payment')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $appointmentId)
            ->assertJsonPath('data.0.payment_status', 'PENDING');

        $orderId = $this->actingAs($user, 'sanctum')
            ->postJson('/api/payments/astrologer-appointment/order', [
                'appointment_id' => $appointmentId,
            ])
            ->assertOk()
            ->json('data.razorpay_order_id');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/payments/razorpay/verify', [
                'razorpay_order_id' => $orderId,
                'razorpay_payment_id' => 'pay_before_admin_review',
                'razorpay_signature' => 'test_signature',
            ])
            ->assertOk()
            ->assertJsonPath('appointment_paid', true);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/appointments?group=payment_received')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $appointmentId)
            ->assertJsonPath('data.0.status', 'open')
            ->assertJsonPath('data.0.payment_status', 'PAID');
    }

    public function test_qr_online_reference_can_be_uploaded_and_admin_can_mark_payment_received(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $admin = AdminUser::query()->create([
            'name' => 'Admin',
            'email' => 'admin-manual-payment@example.com',
            'password' => Hash::make('secret123'),
            'is_active' => true,
        ]);
        $questionIds = AstrologerConsultationQuestion::query()
            ->orderBy('sort_order')
            ->limit(1)
            ->pluck('id')
            ->all();

        $appointmentId = $this->actingAs($user, 'sanctum')
            ->postJson('/api/astrologer-appointments', [
                'question_ids' => $questionIds,
                'preferred_date' => now('Asia/Kolkata')->addDays(3)->toDateString(),
                'preferred_time' => '10:30',
                'preferred_timezone' => 'Asia/Kolkata',
                'contact_number' => '+91 98765 43210',
                'payment_method' => AppointmentPaymentMethod::QR_ONLINE,
                'payment_reference' => 'UPI123456',
            ])
            ->assertCreated()
            ->assertJsonPath('data.payment_method', AppointmentPaymentMethod::QR_ONLINE)
            ->assertJsonPath('data.manual_payment.reference', 'UPI123456')
            ->assertJsonPath('data.can_upload_manual_payment', true)
            ->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->post("/api/astrologer-appointments/{$appointmentId}/manual-payment", [
                'payment_screenshot' => UploadedFile::fake()->image('gpay.png'),
            ])
            ->assertOk()
            ->assertJsonPath('data.manual_payment.original_name', 'gpay.png');

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/appointments/{$appointmentId}", [
                'payment_status' => 'PAID',
                'status' => 'open',
                'timezone' => 'Asia/Kolkata',
            ])
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'PAID')
            ->assertJsonPath('data.status', 'open');
    }

    public function test_admin_cannot_mark_gateway_payment_received_manually(): void
    {
        $user = User::factory()->create();
        $admin = AdminUser::query()->create([
            'name' => 'Admin',
            'email' => 'admin-gateway-override@example.com',
            'password' => Hash::make('secret123'),
            'is_active' => true,
        ]);
        $appointment = AstrologerAppointment::query()->create([
            'user_id' => $user->id,
            'appointment_number' => 'YGT-AST-GATEWAY',
            'status' => 'open',
            'preferred_date' => now('Asia/Kolkata')->addDays(2)->toDateString(),
            'preferred_time' => '11:00',
            'preferred_timezone' => 'Asia/Kolkata',
            'duration_minutes' => 30,
            'maximum_questions' => 3,
            'amount_minor' => 600000,
            'currency' => 'INR',
            'tax_inclusive' => true,
            'payment_stage' => 'before_admin_action',
            'payment_status' => 'PENDING',
            'metadata' => ['payment_method' => 'gateway'],
        ]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/appointments/{$appointment->id}", [
                'payment_status' => 'PAID',
                'status' => 'open',
            ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Only QR / Online appointments can be marked payment received by admin.');

        $this->assertDatabaseHas('astrologer_appointments', [
            'id' => $appointment->id,
            'payment_status' => 'PENDING',
        ]);
    }

    public function test_admin_can_reject_qr_online_payment_with_comment(): void
    {
        $user = User::factory()->create();
        $admin = AdminUser::query()->create([
            'name' => 'Admin',
            'email' => 'admin-reject-payment@example.com',
            'password' => Hash::make('secret123'),
            'is_active' => true,
        ]);
        $questionIds = AstrologerConsultationQuestion::query()
            ->orderBy('sort_order')
            ->limit(1)
            ->pluck('id')
            ->all();

        $appointmentId = $this->actingAs($user, 'sanctum')
            ->postJson('/api/astrologer-appointments', [
                'question_ids' => $questionIds,
                'preferred_date' => now('Asia/Kolkata')->addDays(3)->toDateString(),
                'preferred_time' => '10:30',
                'preferred_timezone' => 'Asia/Kolkata',
                'contact_number' => '+91 98765 43210',
                'payment_method' => AppointmentPaymentMethod::QR_ONLINE,
                'payment_reference' => 'BADREF123',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/appointments/{$appointmentId}", [
                'payment_status' => 'FAILED',
                'admin_note' => 'Reference does not match bank credit.',
            ])
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'FAILED')
            ->assertJsonPath('data.payment_status_label', 'Rejected')
            ->assertJsonPath('data.admin_note', 'Reference does not match bank credit.');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/appointments?group=cancelled_failed')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $appointmentId);
    }

    public function test_failed_gateway_payment_marks_appointment_failed_and_blocks_admin_override(): void
    {
        $user = User::factory()->create();
        $admin = AdminUser::query()->create([
            'name' => 'Admin',
            'email' => 'admin-failed-payment@example.com',
            'password' => Hash::make('secret123'),
            'is_active' => true,
        ]);
        $questionIds = AstrologerConsultationQuestion::query()
            ->orderBy('sort_order')
            ->limit(1)
            ->pluck('id')
            ->all();

        $appointmentId = $this->actingAs($user, 'sanctum')
            ->postJson('/api/astrologer-appointments', [
                'question_ids' => $questionIds,
                'preferred_date' => now('Asia/Kolkata')->addDays(3)->toDateString(),
                'preferred_time' => '10:30',
                'preferred_timezone' => 'Asia/Kolkata',
                'contact_number' => '+91 98765 43210',
            ])
            ->assertCreated()
            ->json('data.id');

        $orderId = $this->actingAs($user, 'sanctum')
            ->postJson('/api/payments/astrologer-appointment/order', [
                'appointment_id' => $appointmentId,
            ])
            ->assertOk()
            ->json('data.razorpay_order_id');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/payments/razorpay/verify', [
                'razorpay_order_id' => $orderId,
                'razorpay_payment_id' => 'pay_failed_signature',
                'razorpay_signature' => 'bad_signature',
            ])
            ->assertStatus(422);

        $this->assertDatabaseHas('astrologer_appointments', [
            'id' => $appointmentId,
            'payment_status' => 'FAILED',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/appointments/{$appointmentId}", [
                'payment_status' => 'PAID',
                'status' => 'open',
            ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Payment failed requests cannot be manually changed to payment received.');
    }

    public function test_another_user_cannot_pay_for_appointment(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $appointment = AstrologerAppointment::query()->create([
            'user_id' => $owner->id,
            'appointment_number' => 'YGT-AST-OTHER',
            'status' => 'open',
            'preferred_date' => now('Asia/Kolkata')->addDays(2)->toDateString(),
            'preferred_time' => '11:00',
            'preferred_timezone' => 'Asia/Kolkata',
            'confirmed_start_at' => now('Asia/Kolkata')->addDays(2),
            'confirmed_end_at' => now('Asia/Kolkata')->addDays(2)->addMinutes(30),
            'confirmed_timezone' => 'Asia/Kolkata',
            'duration_minutes' => 30,
            'maximum_questions' => 3,
            'amount_minor' => 600000,
            'currency' => 'INR',
            'tax_inclusive' => true,
            'payment_stage' => 'before_admin_action',
            'payment_status' => 'PENDING',
        ]);

        $this->actingAs($otherUser, 'sanctum')
            ->postJson('/api/payments/astrologer-appointment/order', [
                'appointment_id' => $appointment->id,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('payment_transactions', 0);
    }

    public function test_admin_available_slots_honor_astrologer_schedule_overrides(): void
    {
        $admin = $this->adminUser('admin-schedule@example.com');
        $astrologer = $this->astrologer('Short Shift Astrologer', [
            'working_days' => [1, 2, 3, 4, 5, 6, 7],
            'working_start_time' => '10:00',
            'working_end_time' => '11:00',
            'duration_minutes' => 20,
            'break_minutes' => 10,
        ]);
        $date = now('Asia/Kolkata')->addDays(4)->toDateString();

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/appointments/available-slots?astrologer_id={$astrologer->id}&slot_date={$date}")
            ->assertOk()
            ->assertJsonPath('data.summary.available', 2);

        $slots = $response->json('data.slots');

        $this->assertSame('10:00 AM - 10:20 AM', $slots[0]['range_label']);
        $this->assertSame($slots[0]['start'], $slots[0]['key']);
        $this->assertSame('10:00 AM', $slots[0]['start_label']);
        $this->assertSame('10:20 AM', $slots[0]['end_label']);
        $this->assertSame('10:30 AM - 10:50 AM', $slots[1]['range_label']);
    }

    public function test_preferred_time_check_is_independent_from_available_slot_list(): void
    {
        $admin = $this->adminUser('admin-preferred-check@example.com');
        $astrologer = $this->astrologer('Preferred Check Astrologer');
        $date = now('Asia/Kolkata')->addDays(4)->toDateString();
        $appointment = $this->paidAppointment([
            'preferred_date' => $date,
            'preferred_time' => '10:00',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/appointments/{$appointment->id}/preferred-slot-availability?astrologer_id={$astrologer->id}")
            ->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonMissingPath('data.slots');

        $slots = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/appointments/available-slots?astrologer_id={$astrologer->id}&slot_date={$date}&appointment_id={$appointment->id}")
            ->assertOk()
            ->assertJsonPath('data.slots.30.is_user_preferred', true)
            ->json('data.slots');

        $this->assertSame('available', $slots[30]['status']);
        $this->assertNull($slots[30]['appointment_id']);
    }

    public function test_available_slot_endpoint_uses_date_scoped_queries_not_per_slot_queries(): void
    {
        $admin = $this->adminUser('admin-query-scope@example.com');
        $astrologer = $this->astrologer('Query Scope Astrologer', [
            'working_days' => [1, 2, 3, 4, 5, 6, 7],
            'working_start_time' => '09:00',
            'working_end_time' => '21:00',
            'duration_minutes' => 20,
            'break_minutes' => 10,
        ]);
        $date = now('Asia/Kolkata')->addDays(6)->toDateString();

        $this->paidAppointment([
            'astrologer_id' => $astrologer->id,
            'status' => 'slot_fixed',
            'confirmed_start_at' => CarbonImmutable::parse($date.' 09:00', 'Asia/Kolkata')->utc(),
            'confirmed_end_at' => CarbonImmutable::parse($date.' 09:20', 'Asia/Kolkata')->utc(),
        ]);
        $this->paidAppointment([
            'astrologer_id' => $astrologer->id,
            'status' => 'slot_fixed',
            'confirmed_start_at' => CarbonImmutable::parse($date, 'Asia/Kolkata')->subDay()->setTime(9, 0)->utc(),
            'confirmed_end_at' => CarbonImmutable::parse($date, 'Asia/Kolkata')->subDay()->setTime(9, 20)->utc(),
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $slots = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/appointments/available-slots?astrologer_id={$astrologer->id}&slot_date={$date}")
            ->assertOk()
            ->assertJsonPath('data.summary.reserved', 1)
            ->json('data.slots');

        $appointmentQueries = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_contains($query['query'], 'astrologer_appointments'))
            ->count();

        $this->assertCount(24, $slots);
        $this->assertLessThanOrEqual(3, $appointmentQueries);
    }

    public function test_admin_available_slots_mark_reservations_blocks_and_leave_days(): void
    {
        $admin = $this->adminUser('admin-blocks@example.com');
        $astrologer = $this->astrologer('Blocked Astrologer', [
            'working_days' => [1, 2, 3, 4, 5, 6, 7],
            'working_start_time' => '09:00',
            'working_end_time' => '10:30',
            'duration_minutes' => 20,
            'break_minutes' => 10,
        ]);
        $date = now('Asia/Kolkata')->addDays(5)->toDateString();
        $this->paidAppointment([
            'astrologer_id' => $astrologer->id,
            'status' => 'slot_fixed',
            'confirmed_start_at' => CarbonImmutable::parse($date.' 09:10', 'Asia/Kolkata')->utc(),
            'confirmed_end_at' => CarbonImmutable::parse($date.' 09:40', 'Asia/Kolkata')->utc(),
        ]);
        AstrologerAppointmentSlotBlock::query()->create([
            'astrologer_id' => $astrologer->id,
            'start_at' => CarbonImmutable::parse($date.' 10:00', 'Asia/Kolkata')->utc(),
            'end_at' => CarbonImmutable::parse($date.' 10:20', 'Asia/Kolkata')->utc(),
            'timezone' => 'Asia/Kolkata',
            'reason' => 'Admin block',
            'created_by_admin_id' => $admin->id,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/appointments/available-slots?astrologer_id={$astrologer->id}&slot_date={$date}")
            ->assertOk();

        $slots = $response->json('data.slots');

        $this->assertSame('reserved', $slots[0]['status']);
        $this->assertSame('reserved', $slots[1]['status']);
        $this->assertSame('blocked', $slots[2]['status']);
        $this->assertSame('Admin block', $slots[2]['blocked_reason']);

        $leaveAstrologer = $this->astrologer('Leave Astrologer', [
            'working_days' => [1, 2, 3, 4, 5, 6, 7],
            'working_start_time' => '09:00',
            'working_end_time' => '10:00',
            'leave_dates' => [$date],
        ]);

        $leaveSlots = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/appointments/available-slots?astrologer_id={$leaveAstrologer->id}&slot_date={$date}")
            ->assertOk()
            ->json('data.slots');

        $this->assertSame('blocked', $leaveSlots[0]['status']);
        $this->assertSame('Astrologer leave', $leaveSlots[0]['blocked_reason']);
    }

    public function test_reservation_for_one_astrologer_does_not_block_another_astrologer(): void
    {
        $admin = $this->adminUser('admin-multi-astro@example.com');
        $first = $this->astrologer('First Astrologer');
        $second = $this->astrologer('Second Astrologer');
        $date = now('Asia/Kolkata')->addDays(6)->toDateString();
        $this->paidAppointment([
            'astrologer_id' => $first->id,
            'status' => 'slot_fixed',
            'confirmed_start_at' => CarbonImmutable::parse($date.' 09:00', 'Asia/Kolkata')->utc(),
            'confirmed_end_at' => CarbonImmutable::parse($date.' 09:20', 'Asia/Kolkata')->utc(),
        ]);

        $firstSlots = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/appointments/available-slots?astrologer_id={$first->id}&slot_date={$date}")
            ->assertOk()
            ->json('data.slots');
        $secondSlots = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/appointments/available-slots?astrologer_id={$second->id}&slot_date={$date}")
            ->assertOk()
            ->json('data.slots');

        $firstNineAm = collect($firstSlots)->firstWhere('start_label', '09:00 AM');
        $secondNineAm = collect($secondSlots)->firstWhere('start_label', '09:00 AM');

        $this->assertSame('reserved', $firstNineAm['status']);
        $this->assertSame('available', $secondNineAm['status']);
    }

    public function test_admin_confirm_slot_uses_backend_timezone_and_prevents_double_booking(): void
    {
        $admin = $this->adminUser('admin-confirm-slot@example.com');
        $astrologer = $this->astrologer('Confirm Astrologer');
        $date = now('Asia/Kolkata')->addDays(7)->toDateString();
        $slotStart = CarbonImmutable::parse($date.' 09:00', 'Asia/Kolkata')->toIso8601String();
        $firstAppointment = $this->paidAppointment();
        $secondAppointment = $this->paidAppointment();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/appointments/{$firstAppointment->id}/confirm-slot", [
                'astrologer_id' => $astrologer->id,
                'slot_start' => $slotStart,
                'meeting_provider' => 'manual',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'slot_fixed')
            ->assertJsonPath('data.duration_minutes', 20);

        $this->assertDatabaseHas('astrologer_appointments', [
            'id' => $firstAppointment->id,
            'astrologer_id' => $astrologer->id,
            'status' => 'slot_fixed',
            'confirmed_timezone' => 'Asia/Kolkata',
            'duration_minutes' => 20,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/appointments/{$secondAppointment->id}/confirm-slot", [
                'astrologer_id' => $astrologer->id,
                'slot_start' => $slotStart,
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'SLOT_NO_LONGER_AVAILABLE');
    }

    public function test_current_appointment_slot_can_be_loaded_and_saved_again(): void
    {
        $admin = $this->adminUser('admin-current-slot@example.com');
        $astrologer = $this->astrologer('Current Slot Astrologer');
        $date = now('Asia/Kolkata')->addDays(8)->toDateString();
        $slotStart = CarbonImmutable::parse($date.' 09:00', 'Asia/Kolkata');
        $appointment = $this->paidAppointment([
            'astrologer_id' => $astrologer->id,
            'status' => 'slot_fixed',
            'confirmed_start_at' => $slotStart->utc(),
            'confirmed_end_at' => $slotStart->addMinutes(20)->utc(),
            'confirmed_timezone' => 'Asia/Kolkata',
            'duration_minutes' => 20,
        ]);

        $slots = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/appointments/available-slots?astrologer_id={$astrologer->id}&slot_date={$date}&appointment_id={$appointment->id}")
            ->assertOk()
            ->json('data.slots');

        $nineAmSlot = collect($slots)->firstWhere('start_label', '09:00 AM');

        $this->assertSame('selected', $nineAmSlot['status']);
        $this->assertTrue($nineAmSlot['is_current_appointment_slot']);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/appointments/{$appointment->id}/confirm-slot", [
                'astrologer_id' => $astrologer->id,
                'slot_start' => $slotStart->toIso8601String(),
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'slot_fixed');
    }

    private function adminUser(string $email): AdminUser
    {
        return AdminUser::query()->create([
            'name' => 'Admin',
            'email' => $email,
            'password' => Hash::make('secret123'),
            'is_active' => true,
        ]);
    }

    private function astrologer(string $name, array $metadata = []): Astrologer
    {
        return Astrologer::query()->create([
            'name' => $name,
            'display_name' => $name,
            'timezone' => 'Asia/Kolkata',
            'is_active' => true,
            'sort_order' => 10,
            'metadata' => array_merge([
                'working_days' => [1, 2, 3, 4, 5, 6, 7],
                'working_start_time' => '00:00',
                'working_end_time' => '00:00',
                'duration_minutes' => 20,
                'break_minutes' => 0,
            ], $metadata),
        ]);
    }

    private function paidAppointment(array $overrides = []): AstrologerAppointment
    {
        $user = User::factory()->create();

        return AstrologerAppointment::query()->create(array_merge([
            'user_id' => $user->id,
            'appointment_number' => 'YGT-AST-'.strtoupper(fake()->bothify('????##')),
            'status' => 'open',
            'preferred_date' => now('Asia/Kolkata')->addDays(3)->toDateString(),
            'preferred_time' => '10:30',
            'preferred_timezone' => 'Asia/Kolkata',
            'duration_minutes' => 20,
            'maximum_questions' => 3,
            'amount_minor' => 600000,
            'currency' => 'INR',
            'tax_inclusive' => true,
            'payment_stage' => 'before_admin_action',
            'payment_status' => 'PAID',
            'paid_at' => now(),
            'metadata' => ['payment_method' => 'gateway'],
        ], $overrides));
    }
}
