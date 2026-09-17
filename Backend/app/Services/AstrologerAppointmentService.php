<?php

namespace App\Services;

use App\Models\AdminUser;
use App\Models\AppointmentLanguage;
use App\Models\AppointmentPaymentMethod;
use App\Models\Astrologer;
use App\Models\AstrologerAppointment;
use App\Models\AstrologerAppointmentActivity;
use App\Models\AstrologerAppointmentSlotBlock;
use App\Models\AstrologerConsultationQuestion;
use App\Models\PaymentMethodSetting;
use App\Models\PredictionProduct;
use App\Models\PredictionRequest;
use App\Models\User;
use App\Support\PredictionViewerSignature;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

class AstrologerAppointmentService
{
    public const PRODUCT_CODE = 'ASTROLOGER_CONSULTATION';

    private ?array $astrologersPayloadCache = null;
    private ?Astrologer $defaultAstrologerCache = null;

    public function activeProduct(): ?PredictionProduct
    {
        $product = PredictionProduct::query()
            ->where('code', self::PRODUCT_CODE)
            ->where('is_active', true)
            ->first();

        return $product ?: $this->ensureDefaultProduct();
    }

    public function configPayload(?PredictionProduct $product = null): array
    {
        $product ??= $this->activeProduct();
        $metadata = $product?->metadata ?? [];

        return [
            'available' => $product !== null,
            'product_id' => $product?->id,
            'code' => $product?->code ?? self::PRODUCT_CODE,
            'title' => $product?->name ?? 'Talk to Astrologer',
            'description' => $product?->description ?? 'Direct online consultation with the astrologer for personalised guidance.',
            'amount_minor' => (int) ($product?->price_inclusive_tax_minor ?? 0),
            'currency' => $product?->currency ?? 'INR',
            'tax_inclusive' => (bool) ($product?->tax_inclusive ?? true),
            'duration_minutes' => 20,
            'maximum_questions' => (int) data_get($metadata, 'maximum_questions', 3),
            'payment_stage' => 'before_admin_action',
            'payment_method' => $this->defaultPaymentMethodCode(),
            'payment_methods' => $this->paymentMethodsPayload(),
            'manual_payment_label' => 'Pay through QR / Online',
            'manual_payment_instructions' => $this->qrPaymentInstructions(),
            'business_timezone' => config('services.prediction_billing.business_timezone', 'Asia/Kolkata'),
            'slot_duration_minutes' => 20,
            'maximum_advance_days' => 60,
            'languages' => $this->appointmentLanguagesPayload(),
            'astrologers' => $this->astrologersPayload(),
            'default_astrologer_id' => $this->defaultAstrologer()?->id,
            'questions' => $this->consultationQuestions(),
        ];
    }

    public function createRequest(User $user, array $validated): AstrologerAppointment
    {
        $product = $this->activeProduct();

        if (! $product) {
            throw new RuntimeException('Astrologer consultation is not available right now.');
        }

        $config = $this->configPayload($product);
        $questions = $this->selectedConsultationQuestions($validated)
            ->map(fn (AstrologerConsultationQuestion $item, int $index): array => [
                'consultation_question_id' => $item->id,
                'sequence' => $index + 1,
                'question' => $item->question,
            ]);

        if ($questions->isEmpty()) {
            throw new RuntimeException('Enter at least one question.');
        }

        return DB::transaction(function () use ($user, $validated, $product, $config, $questions): AstrologerAppointment {
            $predictionRequest = $this->latestPredictionRequestForUser($user);
            $requiresPaymentBeforeReview = $config['payment_stage'] !== 'not_required' && $config['amount_minor'] > 0;
            $paymentMethod = $this->normalizePaymentMethodCode($validated['payment_method'] ?? $config['payment_method']);
            $languageId = $validated['appointment_language_id'] ?? $validated['language_id'] ?? null;
            $manualPayment = $validated['manual_payment'] ?? null;
            $appointment = AstrologerAppointment::query()->create([
                'user_id' => $user->id,
                'prediction_request_id' => $predictionRequest?->id,
                'appointment_language_id' => $languageId,
                'appointment_number' => $this->nextAppointmentNumber(),
                'status' => 'open',
                'notes' => $validated['notes'] ?? null,
                'contact_number' => $validated['contact_number'],
                'preferred_date' => $validated['preferred_date'],
                'preferred_time' => $validated['preferred_time'],
                'preferred_timezone' => $validated['preferred_timezone'],
                'duration_minutes' => $config['duration_minutes'],
                'maximum_questions' => $config['maximum_questions'],
                'amount_minor' => $config['amount_minor'],
                'currency' => $config['currency'],
                'tax_inclusive' => $config['tax_inclusive'],
                'payment_stage' => $config['payment_stage'],
                'payment_method_code' => $requiresPaymentBeforeReview ? $paymentMethod : 'not_required',
                'payment_status' => $requiresPaymentBeforeReview ? 'PENDING' : 'NOT_REQUIRED',
                'metadata' => [
                    'product_id' => $product->id,
                    'product_code' => $product->code,
                    'quoted_product_name' => $product->name,
                    'submitted_timezone' => $validated['preferred_timezone'],
                    'payment_method' => $this->legacyPaymentMethodCode($requiresPaymentBeforeReview ? $paymentMethod : 'not_required'),
                    'manual_payment' => $manualPayment,
                ],
            ]);

            $appointment->questions()->createMany($questions->all());
            $this->record($appointment, 'request_submitted', 'Appointment request submitted.', user: $user, payload: [
                'preferred_date' => $appointment->preferred_date?->toDateString(),
                'preferred_time' => $appointment->preferred_time,
                'question_count' => $questions->count(),
                'payment_required_before_admin_review' => $requiresPaymentBeforeReview,
            ]);

            $this->sendRequestAcknowledgement($appointment->refresh()->load(['user', 'questions']));

            return $appointment->refresh()->load(['user', 'questions']);
        });
    }

    public function availableSlots(string $date, ?string $timezone = null, ?int $astrologerId = null): array
    {
        $astrologerId ??= $this->defaultAstrologer()?->id;
        if (! $astrologerId) {
            return [];
        }

        $payload = app(AppointmentSlotService::class)->availableSlots($astrologerId, $date);

        return collect($payload['slots'])
            ->map(fn (array $slot): array => [
                'type' => 'slot',
                'start_at' => $slot['start'],
                'end_at' => $slot['end'],
                'label' => $slot['label'],
                'range_label' => $slot['range_label'],
                'period' => $slot['period'],
                'duration_minutes' => $payload['slot_duration_minutes'],
                'available' => $slot['status'] === 'available',
                'blocked' => in_array($slot['status'], ['reserved', 'blocked'], true),
                'status' => $slot['status'],
                'appointment_id' => $slot['appointment_id'],
                'blocked_reason' => $slot['blocked_reason'],
            ])
            ->values()
            ->all();
    }

    public function confirmSlot(AstrologerAppointment $appointment, AdminUser $admin, array $validated): AstrologerAppointment
    {
        return DB::transaction(function () use ($appointment, $admin, $validated): AstrologerAppointment {
            $locked = AstrologerAppointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();
            $astrologer = $this->resolveAstrologer((int) ($validated['astrologer_id'] ?? $locked->astrologer_id));
            if (! $astrologer) {
                throw new RuntimeException('Select an available astrologer.');
            }

            $slot = app(AppointmentSlotService::class)->assertSlotAvailable($astrologer->id, $validated['confirmed_start_at'], $locked);

            if ($locked->amount_minor > 0 && $locked->payment_status !== 'PAID') {
                throw new RuntimeException('Payment must be received before fixing a slot.');
            }

            $locked->forceFill([
                'status' => 'slot_fixed',
                'astrologer_id' => $astrologer->id,
                'payment_status' => $locked->payment_status ?: 'NOT_REQUIRED',
                'confirmed_start_at' => $slot['start']->format('Y-m-d H:i:s'),
                'confirmed_end_at' => $slot['end']->format('Y-m-d H:i:s'),
                'confirmed_timezone' => $slot['timezone'],
                'duration_minutes' => $slot['duration_minutes'],
                'meeting_provider' => $validated['meeting_provider'] ?? $locked->meeting_provider ?? 'manual',
                'meeting_link' => $validated['meeting_link'] ?? $locked->meeting_link,
                'admin_note' => $validated['admin_note'] ?? $locked->admin_note,
                'confirmed_by_admin_id' => $admin->id,
                'confirmed_at' => now(),
            ])->save();

            $this->record($locked, 'slot_fixed', 'Appointment slot fixed.', admin: $admin, payload: [
                'confirmed_start_at' => $slot['start']->toIso8601String(),
                'confirmed_end_at' => $slot['end']->toIso8601String(),
                'astrologer_id' => $astrologer->id,
            ]);

            $this->syncCalendar($locked);
            $this->sendMeetingDetails($locked);

            return $locked->refresh()->load(['user', 'questions', 'activities']);
        });
    }

    public function markPaid(AstrologerAppointment $appointment): void
    {
        $appointment->forceFill([
            'payment_status' => 'PAID',
            'paid_at' => $appointment->paid_at ?? now(),
            'status' => $appointment->confirmed_start_at ? 'slot_fixed' : 'open',
        ])->save();

        $this->record($appointment, 'payment_received', 'Appointment payment received.');
        $this->syncCalendar($appointment);
        if ($appointment->confirmed_start_at) {
            $this->sendMeetingDetails($appointment);
        }
    }

    public function markPaymentFailed(AstrologerAppointment $appointment, ?string $failureCode = null, ?string $failureDescription = null): void
    {
        if ($appointment->payment_status === 'PAID') {
            return;
        }

        $metadata = $appointment->metadata ?? [];
        $metadata['payment_failure'] = array_filter([
            'code' => $failureCode,
            'description' => $failureDescription,
            'failed_at' => now()->toJSON(),
        ]);

        $appointment->forceFill([
            'payment_status' => 'FAILED',
            'metadata' => $metadata,
        ])->save();

        $this->record($appointment, 'payment_failed', 'Appointment payment failed.', payload: [
            'failure_code' => $failureCode,
            'failure_description' => $failureDescription,
        ]);
    }

    public function attachManualPaymentProof(AstrologerAppointment $appointment, User $user, array $fileData): AstrologerAppointment
    {
        $metadata = $appointment->metadata ?? [];
        $metadata['payment_method'] = 'manual_gpay';
        $metadata['manual_payment'] = [
            'path' => $fileData['path'],
            'original_name' => $fileData['original_name'],
            'mime_type' => $fileData['mime_type'],
            'reference' => $fileData['reference'] ?? data_get($metadata, 'manual_payment.reference'),
            'uploaded_at' => now()->toJSON(),
            'review_status' => 'waiting_admin_confirmation',
        ];

        $appointment->forceFill([
            'payment_status' => $appointment->payment_status === 'PAID' ? 'PAID' : 'PENDING',
            'payment_method_code' => AppointmentPaymentMethod::QR_ONLINE,
            'metadata' => $metadata,
        ])->save();

        $this->record($appointment, 'manual_payment_uploaded', 'Manual payment proof uploaded.', user: $user, payload: [
            'original_name' => $fileData['original_name'],
            'mime_type' => $fileData['mime_type'],
        ]);

        return $appointment->refresh()->load(['user', 'questions', 'activities']);
    }

    public function cancel(AstrologerAppointment $appointment, ?User $user = null, ?AdminUser $admin = null, ?string $reason = null): AstrologerAppointment
    {
        return DB::transaction(function () use ($appointment, $user, $admin, $reason): AstrologerAppointment {
            $locked = AstrologerAppointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();

            $locked->forceFill([
                'status' => 'cancelled',
                'cancelled_at' => $locked->cancelled_at ?? now(),
            ])->save();

            $this->cancelCalendar($locked);
            $this->record($locked, 'cancelled', $admin ? 'Appointment cancelled by admin.' : 'Appointment cancelled by user.', user: $user, admin: $admin, payload: [
                'reason' => $reason,
            ]);
            $this->sendCancellationNotice($locked);

            return $locked->refresh()->load(['user', 'questions', 'activities']);
        });
    }

    public function record(AstrologerAppointment $appointment, string $eventType, string $title, ?User $user = null, ?AdminUser $admin = null, array $payload = []): void
    {
        AstrologerAppointmentActivity::query()->create([
            'appointment_id' => $appointment->id,
            'actor_user_id' => $user?->id,
            'actor_admin_id' => $admin?->id,
            'event_type' => $eventType,
            'title' => $title,
            'payload' => $payload,
        ]);
    }

    public function payload(AstrologerAppointment $appointment, bool $includeAdmin = false): array
    {
        $appointment->loadMissing(['user', 'questions', 'activities', 'predictionRequest', 'appointmentLanguage']);
        $viewerUrl = $includeAdmin && $appointment->predictionRequest
            ? $this->predictionResponseViewerUrl($appointment->predictionRequest)
            : null;
        $identity = $includeAdmin && $appointment->predictionRequest
            ? $this->appointmentIdentityPayload($appointment->predictionRequest)
            : null;

        return [
            'id' => $appointment->id,
            'appointment_number' => $appointment->appointment_number,
            'prediction_request_id' => $includeAdmin ? $appointment->prediction_request_id : null,
            'viewer_url' => $viewerUrl,
            'identity' => $identity,
            'status' => $appointment->status,
            'status_label' => $this->appointmentStatusLabel($appointment->status),
            'questions' => $appointment->questions->map(fn ($question): array => [
                'consultation_question_id' => $question->consultation_question_id,
                'sequence' => $question->sequence,
                'question' => $question->question,
            ])->values(),
            'questions_count' => $appointment->questions->count(),
            'notes' => $appointment->notes,
            'contact_number' => $appointment->contact_number,
            'preferred_date' => $appointment->preferred_date?->toDateString(),
            'preferred_time' => $appointment->preferred_time,
            'preferred_timezone' => $appointment->preferred_timezone,
            'confirmed_start_at' => $this->appointmentDateTimePayload($appointment, 'confirmed_start_at'),
            'confirmed_end_at' => $this->appointmentDateTimePayload($appointment, 'confirmed_end_at'),
            'confirmed_timezone' => $appointment->confirmed_timezone,
            'astrologer_id' => $appointment->astrologer_id,
            'available_astrologers' => $includeAdmin ? $this->astrologersPayload() : [],
            'default_astrologer_id' => $includeAdmin ? $this->defaultAstrologer()?->id : null,
            'duration_minutes' => $appointment->duration_minutes,
            'amount_minor' => $appointment->amount_minor,
            'currency' => $appointment->currency,
            'payment_stage' => $appointment->payment_stage,
            'payment_status' => $appointment->payment_status,
            'payment_method' => $this->appointmentPaymentMethod($appointment),
            'payment_method_label' => $this->paymentMethodLabel($this->appointmentPaymentMethod($appointment)),
            'payment_status_label' => $this->paymentStatusLabel($appointment),
            'appointment_language' => $appointment->appointmentLanguage ? [
                'id' => $appointment->appointmentLanguage->id,
                'name' => $appointment->appointmentLanguage->name,
                'code' => $appointment->appointmentLanguage->code,
            ] : null,
            'can_pay' => $this->canPayByGateway($appointment),
            'can_upload_manual_payment' => $this->canUploadManualPayment($appointment),
            'manual_payment' => $this->manualPaymentPayload($appointment, $includeAdmin),
            'meeting_provider' => $appointment->meeting_provider,
            'meeting_link' => $appointment->meeting_link,
            'calendar_event_id' => $includeAdmin ? $appointment->calendar_event_id : null,
            'submitted_at' => $appointment->created_at?->toJSON(),
            'confirmed_at' => $appointment->confirmed_at?->toJSON(),
            'completed_at' => $appointment->completed_at?->toJSON(),
            'cancelled_at' => $appointment->cancelled_at?->toJSON(),
            'user' => $includeAdmin ? [
                'id' => $appointment->user?->id,
                'name' => $appointment->user?->name,
                'email' => $appointment->user?->email,
                'phone_e164' => $appointment->user?->phone_e164,
            ] : null,
            'admin_note' => $includeAdmin ? $appointment->admin_note : null,
            'activities' => $includeAdmin ? $appointment->activities->map(fn ($activity): array => [
                'event_type' => $activity->event_type,
                'title' => $activity->title,
                'payload' => $activity->payload,
                'created_at' => $activity->created_at?->toJSON(),
            ])->values() : [],
        ];
    }

    private function canPayByGateway(AstrologerAppointment $appointment): bool
    {
        $paymentMethod = $this->appointmentPaymentMethod($appointment);

        return $paymentMethod === AppointmentPaymentMethod::PAYMENT_GATEWAY
            && $appointment->amount_minor > 0
            && ! in_array($appointment->payment_status, ['PAID', 'FAILED'], true)
            && ! in_array($appointment->status, ['completed', 'cancelled'], true);
    }

    private function canUploadManualPayment(AstrologerAppointment $appointment): bool
    {
        $paymentMethod = $this->appointmentPaymentMethod($appointment);

        return $paymentMethod === AppointmentPaymentMethod::QR_ONLINE
            && $appointment->amount_minor > 0
            && ! in_array($appointment->payment_status, ['PAID', 'FAILED'], true)
            && ! in_array($appointment->status, ['completed', 'cancelled'], true);
    }

    private function appointmentDateTimePayload(AstrologerAppointment $appointment, string $field): ?string
    {
        $raw = $appointment->getRawOriginal($field);
        if (! $raw) {
            return null;
        }

        $timezone = $appointment->confirmed_timezone ?: 'Asia/Kolkata';

        return CarbonImmutable::parse($raw, $timezone)->toIso8601String();
    }

    private function paymentStatusLabel(AstrologerAppointment $appointment): string
    {
        if ($appointment->payment_status === 'PAID') {
            return 'Paid';
        }

        if ($appointment->payment_status === 'FAILED') {
            if (data_get($appointment->metadata ?? [], 'payment_failure.code') === 'admin_rejected') {
                return 'Rejected';
            }

            return 'Payment Failed';
        }

        if ($appointment->payment_status === 'NOT_REQUIRED') {
            return 'Not Required';
        }

        if ($this->appointmentPaymentMethod($appointment) === AppointmentPaymentMethod::QR_ONLINE) {
            return 'Pending Payment Verification';
        }

        return 'Not Paid';
    }

    private function appointmentStatusLabel(?string $status): string
    {
        if (in_array($status, ['pending', 'reviewing', 'slot_proposed', 'payment_pending', 'open'], true)) {
            return 'Open';
        }

        if (in_array($status, ['booked', 'upcoming', 'slot_fixed'], true)) {
            return 'Slot Fixed';
        }

        return ucwords(str_replace('_', ' ', (string) $status));
    }

    private function manualPaymentPayload(AstrologerAppointment $appointment, bool $includeAdmin): ?array
    {
        $manualPayment = data_get($appointment->metadata ?? [], 'manual_payment');

        if (! is_array($manualPayment)) {
            return null;
        }

        return [
            'original_name' => data_get($manualPayment, 'original_name'),
            'mime_type' => data_get($manualPayment, 'mime_type'),
            'uploaded_at' => data_get($manualPayment, 'uploaded_at'),
            'review_status' => data_get($manualPayment, 'review_status'),
            'file_url' => $includeAdmin ? url('/api/admin/appointments/'.$appointment->id.'/manual-payment-file') : null,
        ];
    }

    public function activePaymentMethodCodes(): array
    {
        $codes = collect($this->paymentMethodsPayload())
            ->pluck('code')
            ->values()
            ->all();

        return $codes ?: [AppointmentPaymentMethod::PAYMENT_GATEWAY];
    }

    public function allowedAppointmentLanguageIds(): array
    {
        return collect($this->appointmentLanguagesPayload())
            ->pluck('id')
            ->values()
            ->all();
    }

    public function normalizePaymentMethodCode(?string $method): string
    {
        return match ($method) {
            'gateway' => AppointmentPaymentMethod::PAYMENT_GATEWAY,
            'manual_gpay' => AppointmentPaymentMethod::QR_ONLINE,
            AppointmentPaymentMethod::QR_ONLINE => AppointmentPaymentMethod::QR_ONLINE,
            'not_required' => 'not_required',
            default => AppointmentPaymentMethod::PAYMENT_GATEWAY,
        };
    }

    public function appointmentPaymentMethod(AstrologerAppointment $appointment): string
    {
        if ($appointment->payment_method_code) {
            return $this->normalizePaymentMethodCode($appointment->payment_method_code);
        }

        return $this->normalizePaymentMethodCode(data_get($appointment->metadata ?? [], 'payment_method', $appointment->amount_minor > 0 ? 'gateway' : 'not_required'));
    }

    private function legacyPaymentMethodCode(string $method): string
    {
        return match ($method) {
            AppointmentPaymentMethod::PAYMENT_GATEWAY => 'gateway',
            AppointmentPaymentMethod::QR_ONLINE => 'manual_gpay',
            default => $method,
        };
    }

    private function paymentMethodsPayload(): array
    {
        $this->ensureDefaultPaymentMethods();
        $setting = $this->paymentMethodSetting();

        return AppointmentPaymentMethod::query()
            ->where('is_active', true)
            ->whereIn('code', array_filter([
                $setting->payment_gateway_enabled ? AppointmentPaymentMethod::PAYMENT_GATEWAY : null,
                $setting->gpay_qr_enabled && $setting->qr_image_path ? AppointmentPaymentMethod::QR_ONLINE : null,
            ]))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (AppointmentPaymentMethod $method): array => [
                'id' => $method->id,
                'code' => $method->code,
                'name' => $method->code === AppointmentPaymentMethod::QR_ONLINE ? 'Pay via GPay QR' : 'Pay Online',
                'subtitle' => $method->code === AppointmentPaymentMethod::QR_ONLINE ? 'Scan QR and submit proof' : 'Existing Payment Gateway',
                'instructions' => $method->code === AppointmentPaymentMethod::QR_ONLINE ? 'Scan the QR code, complete payment, then upload the payment screenshot.' : 'Pay securely through Razorpay.',
                'qr_image_url' => $method->code === AppointmentPaymentMethod::QR_ONLINE ? $setting->qrImageUrl() : null,
            ])
            ->values()
            ->all();
    }

    private function defaultPaymentMethodCode(): string
    {
        return data_get($this->paymentMethodsPayload(), '0.code', AppointmentPaymentMethod::PAYMENT_GATEWAY);
    }

    private function paymentMethodLabel(string $method): string
    {
        return match ($method) {
            AppointmentPaymentMethod::QR_ONLINE => 'Pay through QR / Online',
            AppointmentPaymentMethod::PAYMENT_GATEWAY => 'Payment Gateway',
            default => ucwords(str_replace('_', ' ', $method)),
        };
    }

    private function qrPaymentInstructions(): ?string
    {
        $method = collect($this->paymentMethodsPayload())
            ->firstWhere('code', AppointmentPaymentMethod::QR_ONLINE);

        return is_array($method) ? ($method['instructions'] ?? null) : null;
    }

    private function paymentMethodSetting(): PaymentMethodSetting
    {
        return PaymentMethodSetting::query()->firstOrCreate(
            ['service_type' => PaymentMethodSetting::TALK_TO_ASTROLOGER],
            ['payment_gateway_enabled' => true, 'gpay_qr_enabled' => false]
        );
    }

    private function appointmentLanguagesPayload(): array
    {
        $this->ensureDefaultAppointmentLanguages();

        return AppointmentLanguage::query()
            ->where('allow_appointment', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (AppointmentLanguage $language): array => [
                'id' => $language->id,
                'code' => $language->code,
                'name' => $language->name,
            ])
            ->values()
            ->all();
    }

    private function ensureDefaultAppointmentLanguages(): void
    {
        if (AppointmentLanguage::query()->exists()) {
            return;
        }

        foreach ([
            ['Tamil', 'ta', true, 1],
            ['English', 'en', true, 2],
            ['Hindi', 'hi', false, 3],
        ] as [$name, $code, $allowAppointment, $sortOrder]) {
            AppointmentLanguage::query()->create([
                'name' => $name,
                'code' => $code,
                'allow_appointment' => $allowAppointment,
                'sort_order' => $sortOrder,
            ]);
        }
    }

    private function ensureDefaultPaymentMethods(): void
    {
        if (AppointmentPaymentMethod::query()->exists()) {
            return;
        }

        foreach ([
            [AppointmentPaymentMethod::PAYMENT_GATEWAY, 'Payment Gateway', 1, 'Pay securely through Razorpay.'],
            [AppointmentPaymentMethod::QR_ONLINE, 'Pay through QR / Online', 2, 'Pay through QR, GPay, or online transfer, then upload the payment proof.'],
        ] as [$code, $name, $sortOrder, $instructions]) {
            AppointmentPaymentMethod::query()->create([
                'code' => $code,
                'name' => $name,
                'is_active' => true,
                'sort_order' => $sortOrder,
                'instructions' => $instructions,
            ]);
        }
    }

    private function latestPredictionRequestForUser(User $user): ?PredictionRequest
    {
        return PredictionRequest::query()
            ->whereRaw('LOWER(TRIM(email)) = ?', [strtolower(trim((string) $user->email))])
            ->latest('completed_at')
            ->latest('id')
            ->first();
    }

    private function ensureDefaultProduct(): PredictionProduct
    {
        return PredictionProduct::query()->updateOrCreate(
            ['code' => self::PRODUCT_CODE],
            [
                'name' => 'Talk to Astrologer',
                'description' => 'Direct online consultation with the astrologer for personalised guidance.',
                'prediction_type' => 'appointment',
                'price_inclusive_tax_minor' => 600000,
                'currency' => 'INR',
                'tax_inclusive' => true,
                'tax_rate' => null,
                'is_active' => true,
                'metadata' => [
                    'maximum_questions' => 3,
                ],
            ]
        );
    }

    private function consultationQuestions(): array
    {
        $this->ensureDefaultQuestions();

        return AstrologerConsultationQuestion::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (AstrologerConsultationQuestion $question): array => [
                'id' => $question->id,
                'title' => $question->title,
                'question' => $question->question,
            ])
            ->values()
            ->all();
    }

    private function slotPeriod(CarbonImmutable $time): string
    {
        $hour = (int) $time->format('G');

        return $hour >= 9 && $hour < 21 ? 'day' : 'night';
    }

    private function breakLabel(CarbonImmutable $start, CarbonImmutable $end): string
    {
        $displayStart = $start->addMinute();

        if ($displayStart->gt($end)) {
            $displayStart = $start;
        }

        return $displayStart->format('h:i A').' - '.$end->format('h:i A');
    }

    private function selectedConsultationQuestions(array $validated)
    {
        $ids = collect($validated['question_ids'] ?? [])
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty() && ! empty($validated['questions'])) {
            return collect($validated['questions'])
                ->map(function (array $item, int $index): AstrologerConsultationQuestion {
                    return new AstrologerConsultationQuestion([
                        'id' => null,
                        'title' => 'Question '.($index + 1),
                        'question' => trim((string) $item['question']),
                        'is_active' => true,
                    ]);
                })
                ->filter(fn (AstrologerConsultationQuestion $item): bool => $item->question !== '')
                ->values();
        }

        return AstrologerConsultationQuestion::query()
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->get()
            ->sortBy(fn (AstrologerConsultationQuestion $question): int => $ids->search($question->id))
            ->values();
    }

    private function ensureDefaultQuestions(): void
    {
        if (AstrologerConsultationQuestion::query()->exists()) {
            return;
        }

        $questions = [
            ['Career', 'What guidance do I need for my career growth or job change?', 1],
            ['Business', 'What should I understand about my business growth, timing, or obstacles?', 2],
            ['Finance', 'What financial period am I entering and how should I plan money decisions?', 3],
            ['Health', 'What health and wellbeing areas need more attention now?', 4],
            ['Marriage', 'What guidance is available for marriage timing or relationship harmony?', 5],
            ['Family', 'How can I handle current family responsibilities or relationship concerns?', 6],
            ['Education', 'What is the right direction for education, exams, or skill development?', 7],
            ['Property', 'Is this a favourable time for property, house, or vehicle decisions?', 8],
            ['Foreign Travel', 'What are the chances and timing for foreign travel or relocation?', 9],
            ['Spiritual Remedy', 'Which spiritual remedy or temple guidance can help my present situation?', 10],
        ];

        foreach ($questions as [$title, $question, $sortOrder]) {
            AstrologerConsultationQuestion::query()->create([
                'title' => $title,
                'question' => $question,
                'sort_order' => $sortOrder,
                'is_active' => true,
            ]);
        }
    }

    private function slotOverlaps(CarbonImmutable $start, CarbonImmutable $end, int $buffer = 0, ?int $exceptAppointmentId = null, ?int $astrologerId = null): bool
    {
        $startAt = $start->subMinutes($buffer)->toDateTimeString();
        $endAt = $end->addMinutes($buffer)->toDateTimeString();

        $appointmentOverlap = AstrologerAppointment::query()
            ->whereIn('status', ['slot_fixed', 'slot_proposed', 'payment_pending', 'booked', 'upcoming'])
            ->when($astrologerId, fn ($query) => $query->where(function ($inner) use ($astrologerId): void {
                $inner->whereNull('astrologer_id')->orWhere('astrologer_id', $astrologerId);
            }))
            ->when($exceptAppointmentId, fn ($query) => $query->whereKeyNot($exceptAppointmentId))
            ->whereNotNull('confirmed_start_at')
            ->where('confirmed_start_at', '<', $endAt)
            ->where('confirmed_end_at', '>', $startAt)
            ->exists();

        if ($appointmentOverlap) {
            return true;
        }

        return AstrologerAppointmentSlotBlock::query()
            ->when($astrologerId, fn ($query) => $query->where(function ($inner) use ($astrologerId): void {
                $inner->whereNull('astrologer_id')->orWhere('astrologer_id', $astrologerId);
            }))
            ->where('start_at', '<', $endUtc)
            ->where('end_at', '>', $startUtc)
            ->exists();
    }

    private function astrologersPayload(): array
    {
        if ($this->astrologersPayloadCache !== null) {
            return $this->astrologersPayloadCache;
        }

        $this->ensureDefaultAstrologer();

        $this->astrologersPayloadCache = Astrologer::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (Astrologer $astrologer): array => [
                'id' => $astrologer->id,
                'name' => $astrologer->display_name ?: $astrologer->name,
                'timezone' => $astrologer->timezone,
            ])
            ->values()
            ->all();

        return $this->astrologersPayloadCache;
    }

    private function resolveAstrologer(?int $astrologerId): ?Astrologer
    {
        $this->ensureDefaultAstrologer();

        if ($astrologerId) {
            $astrologer = Astrologer::query()
                ->whereKey($astrologerId)
                ->where('is_active', true)
                ->first();

            if ($astrologer) {
                return $astrologer;
            }
        }

        return $this->defaultAstrologer();
    }

    private function defaultAstrologer(): ?Astrologer
    {
        if ($this->defaultAstrologerCache !== null) {
            return $this->defaultAstrologerCache;
        }

        $this->ensureDefaultAstrologer();

        $this->defaultAstrologerCache = Astrologer::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();

        return $this->defaultAstrologerCache;
    }

    private function ensureDefaultAstrologer(): void
    {
        if (Astrologer::query()->exists()) {
            return;
        }

        Astrologer::query()->create([
            'name' => 'Default Astrologer',
            'display_name' => 'Default Astrologer',
            'timezone' => 'Asia/Kolkata',
            'is_active' => true,
            'sort_order' => 1,
            'metadata' => ['created_for' => 'appointment_testing'],
        ]);
    }

    private function nextAppointmentNumber(): string
    {
        do {
            $maxNumeric = AstrologerAppointment::query()
                ->pluck('appointment_number')
                ->filter(fn ($value): bool => preg_match('/^\d+$/', (string) $value) === 1)
                ->map(fn ($value): int => (int) $value)
                ->max();
            $number = (string) max(100001, ((int) $maxNumeric) + 1);
        } while (AstrologerAppointment::query()->where('appointment_number', $number)->exists());

        return $number;
    }

    private function syncCalendar(AstrologerAppointment $appointment): void
    {
        if (! $appointment->confirmed_start_at || ! $appointment->confirmed_end_at) {
            return;
        }

        $calendar = app(GoogleCalendarService::class);

        if (! $calendar->configured()) {
            $appointment->forceFill([
                'calendar_event_id' => $appointment->calendar_event_id ?? 'pending-calendar-'.$appointment->id,
                'meeting_provider' => $appointment->meeting_provider ?: 'manual',
            ])->save();

            $this->record($appointment, 'calendar_pending', 'Calendar event is pending provider configuration.');

            return;
        }

        try {
            $event = $calendar->upsertAppointmentEvent($appointment);
            $videoEntry = collect(data_get($event, 'conferenceData.entryPoints', []))
                ->firstWhere('entryPointType', 'video');
            $meetingLink = data_get($event, 'hangoutLink')
                ?: data_get($videoEntry, 'uri');

            $appointment->forceFill([
                'calendar_event_id' => data_get($event, 'id', $appointment->calendar_event_id),
                'meeting_provider' => $meetingLink ? 'google_meet' : ($appointment->meeting_provider ?: 'manual'),
                'meeting_link' => $meetingLink ?: $appointment->meeting_link,
            ])->save();

            $this->record($appointment, 'calendar_synced', 'Google Calendar event saved.', payload: [
                'calendar_event_id' => $appointment->calendar_event_id,
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Google Calendar appointment sync failed.', [
                'appointment_id' => $appointment->id,
                'error' => $exception->getMessage(),
            ]);

            $this->record($appointment, 'calendar_failed', 'Google Calendar sync failed; appointment remains saved.', payload: [
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function cancelCalendar(AstrologerAppointment $appointment): void
    {
        $calendar = app(GoogleCalendarService::class);

        if (! $calendar->configured()) {
            return;
        }

        try {
            $calendar->cancelAppointmentEvent($appointment);
            $this->record($appointment, 'calendar_cancelled', 'Google Calendar event cancelled.');
        } catch (\Throwable $exception) {
            Log::warning('Google Calendar appointment cancellation failed.', [
                'appointment_id' => $appointment->id,
                'error' => $exception->getMessage(),
            ]);
            $this->record($appointment, 'calendar_cancel_failed', 'Google Calendar cancellation failed; appointment remains cancelled.', payload: [
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function predictionResponseViewerUrl(PredictionRequest $predictionRequest): string
    {
        $expires = now()->addMinutes(30)->timestamp;
        $signature = PredictionViewerSignature::sign($predictionRequest->id, true, $expires, (string) config('app.key'));

        return url('/view-prediction-response.php').'?'.http_build_query([
            'id' => $predictionRequest->id,
            'embedded' => 1,
            'expires' => $expires,
            'signature' => $signature,
        ]);
    }

    private function appointmentIdentityPayload(PredictionRequest $predictionRequest): array
    {
        $normalized = $predictionRequest->normalized_astrology_data ?? [];
        $value = is_array(data_get($normalized, 'value')) ? data_get($normalized, 'value') : $normalized;
        $birth = data_get($value, 'birth', []);
        $identity = data_get($value, 'identity', []);

        return [
            'submitted_details' => [
                'name' => $predictionRequest->full_name,
                'date_of_birth' => $predictionRequest->date_of_birth?->toDateString() ?: data_get($birth, 'date_of_birth'),
                'time_of_birth' => $predictionRequest->time_of_birth ?: data_get($birth, 'time_of_birth'),
                'place_of_birth' => $predictionRequest->place_of_birth ?: data_get($birth, 'place_of_birth'),
                'timezone' => $predictionRequest->timezone ?: data_get($birth, 'timezone'),
            ],
            'astrology' => [
                'rasi' => data_get($identity, 'rasi'),
                'nakshatra' => data_get($identity, 'nakshatra'),
                'lagnam' => data_get($identity, 'lagnam'),
            ],
        ];
    }

    private function sendRequestAcknowledgement(AstrologerAppointment $appointment): void
    {
        $body = "Dear {$appointment->user?->name},\n\nYour consultation request {$appointment->appointment_number} has been received.\nPreferred: {$appointment->preferred_date?->toDateString()} {$appointment->preferred_time} {$appointment->preferred_timezone}\nQuestions: {$appointment->questions->count()}\nDuration: {$appointment->duration_minutes} minutes\nStatus: Pending Review\n\nOur team will review availability and send confirmed appointment details by email.";
        Mail::raw($body, fn ($message) => $message->to($appointment->user?->email)->subject('Your astrologer consultation request'));
        $appointment->forceFill(['request_acknowledgement_sent_at' => now()])->save();
    }

    private function sendPaymentRequired(AstrologerAppointment $appointment): void
    {
        Mail::raw(
            "Your consultation slot for {$appointment->appointment_number} is confirmed. Please complete payment from My Account to book the appointment.",
            fn ($message) => $message->to($appointment->user?->email)->subject('Payment required for your astrologer consultation')
        );
        $appointment->forceFill(['payment_email_sent_at' => now()])->save();
    }

    private function sendMeetingDetails(AstrologerAppointment $appointment): void
    {
        $time = $appointment->getRawOriginal('confirmed_start_at')
            ? CarbonImmutable::parse($appointment->getRawOriginal('confirmed_start_at'), $appointment->confirmed_timezone ?: 'Asia/Kolkata')->format('d M Y, h:i A')
            : 'Meeting time will be shared soon.';

        Mail::raw(
            "Your consultation {$appointment->appointment_number} is booked.\nTime: {$time}\nDuration: {$appointment->duration_minutes} minutes\nMeeting: ".($appointment->meeting_link ?: 'Meeting link will be shared soon.'),
            fn ($message) => $message->to($appointment->user?->email)->subject('Your astrologer consultation is booked')
        );
        $appointment->forceFill(['meeting_email_sent_at' => now()])->save();
    }

    private function sendCancellationNotice(AstrologerAppointment $appointment): void
    {
        Mail::raw(
            "Your consultation {$appointment->appointment_number} has been cancelled. Please contact support if you need help booking another slot.",
            fn ($message) => $message->to($appointment->user?->email)->subject('Your astrologer consultation was cancelled')
        );
    }
}
