<?php

namespace App\Http\Controllers;

use App\Models\AstrologerAppointment;
use App\Models\AppointmentPaymentMethod;
use App\Services\AstrologerAppointmentService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AstrologerAppointmentController extends Controller
{
    public function config(AstrologerAppointmentService $service): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $service->configPayload(),
        ]);
    }

    public function index(Request $request, AstrologerAppointmentService $service): JsonResponse
    {
        $appointments = AstrologerAppointment::query()
            ->with('questions')
            ->where('user_id', $request->user()->id)
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (AstrologerAppointment $appointment): array => $service->payload($appointment));

        return response()->json([
            'success' => true,
            'data' => $appointments->values(),
        ]);
    }

    public function store(Request $request, AstrologerAppointmentService $service): JsonResponse
    {
        $config = $service->configPayload();
        $timezone = $config['business_timezone'] ?: 'Asia/Kolkata';
        $now = CarbonImmutable::now($timezone);
        $activePaymentMethodCodes = $service->activePaymentMethodCodes();

        if (! $activePaymentMethodCodes) {
            return response()->json([
                'success' => false,
                'message' => 'Payment options are currently unavailable. Please try again later.',
                'errors' => ['payment_method' => ['Payment options are currently unavailable. Please try again later.']],
            ], 422);
        }

        $validated = $request->validate([
            'appointment_language_id' => ['nullable', 'integer', Rule::in($service->allowedAppointmentLanguageIds())],
            'question_ids' => ['required_without:questions', 'array', 'min:1', 'max:'.max(1, (int) $config['maximum_questions'])],
            'question_ids.*' => ['integer', 'distinct', Rule::exists('astrologer_consultation_questions', 'id')->where('is_active', true)],
            'questions' => ['required_without:question_ids', 'array', 'min:1', 'max:'.max(1, (int) $config['maximum_questions'])],
            'questions.*.question' => ['required_with:questions', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'contact_number' => ['required', 'string', 'max:32', 'regex:/^[0-9+()\\-\\s]{7,32}$/'],
            'preferred_date' => ['required', 'date_format:Y-m-d'],
            'preferred_time' => ['required', 'date_format:H:i'],
            'preferred_timezone' => ['nullable', 'string', 'max:64'],
            'payment_method' => ['nullable', Rule::in($activePaymentMethodCodes)],
            'payment_screenshot' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        if (! preg_match('/^\d{2}:(00|30)$/', $validated['preferred_time'])) {
            return response()->json([
                'success' => false,
                'message' => 'Preferred time must be selected in 30 minute intervals.',
                'errors' => ['preferred_time' => ['Preferred time must be selected in 30 minute intervals.']],
            ], 422);
        }

        $paymentMethod = $validated['payment_method'] ?? $config['payment_method'];
        if ($paymentMethod === AppointmentPaymentMethod::QR_ONLINE && ! $request->hasFile('payment_screenshot')) {
            return response()->json([
                'success' => false,
                'message' => 'Upload the payment proof screenshot.',
                'errors' => ['payment_screenshot' => ['Upload the payment proof screenshot.']],
            ], 422);
        }

        $preferredAt = CarbonImmutable::parse($validated['preferred_date'].' '.$validated['preferred_time'], $timezone);
        if ($preferredAt->lte($now)) {
            return response()->json([
                'success' => false,
                'message' => 'Preferred appointment date and time must be in the future.',
                'errors' => ['preferred_time' => ['Preferred appointment date and time must be in the future.']],
            ], 422);
        }

        $validated['preferred_timezone'] = $timezone;
        $validated['appointment_language_id'] ??= data_get($config, 'languages.0.id');
        if ($paymentMethod === AppointmentPaymentMethod::QR_ONLINE) {
            $validated['manual_payment'] = array_filter([
                'uploaded_at' => now()->toJSON(),
                'review_status' => 'waiting_admin_confirmation',
            ]);

            if ($request->hasFile('payment_screenshot')) {
                $file = $request->file('payment_screenshot');
                $validated['manual_payment'] = array_merge($validated['manual_payment'], [
                    'path' => $file->store('appointment-manual-payments'),
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                ]);
            }
        }
        $appointment = $service->createRequest($request->user(), $validated);

        return response()->json([
            'success' => true,
            'message' => 'Thank you. Your consultation request has been received. Our team will review your preferred date and time and send the confirmed appointment details by email.',
            'data' => $service->payload($appointment),
        ], 201);
    }

    public function uploadManualPayment(Request $request, AstrologerAppointment $appointment, AstrologerAppointmentService $service): JsonResponse
    {
        if ($appointment->user_id !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'You are not authorised to access this appointment.'], 403);
        }

        if ($appointment->payment_status === 'PAID') {
            return response()->json(['success' => false, 'message' => 'Payment is already received for this appointment.'], 409);
        }

        $validated = $request->validate([
            'payment_screenshot' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $file = $validated['payment_screenshot'];
        $path = $file->store('appointment-manual-payments');
        $appointment = $service->attachManualPaymentProof($appointment, $request->user(), [
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Payment screenshot uploaded. Admin will verify the payment.',
            'data' => $service->payload($appointment),
        ]);
    }

    public function cancel(Request $request, AstrologerAppointment $appointment, AstrologerAppointmentService $service): JsonResponse
    {
        if ($appointment->user_id !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'You are not authorised to access this appointment.'], 403);
        }

        if (in_array($appointment->status, ['completed', 'cancelled'], true)) {
            return response()->json(['success' => false, 'message' => 'This appointment cannot be cancelled.'], 409);
        }

        $appointment = $service->cancel($appointment, user: $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Appointment cancelled.',
            'data' => $service->payload($appointment),
        ]);
    }
}
