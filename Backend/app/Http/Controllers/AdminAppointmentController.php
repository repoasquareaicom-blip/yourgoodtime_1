<?php

namespace App\Http\Controllers;

use App\Models\AppointmentPaymentMethod;
use App\Models\AstrologerAppointment;
use App\Services\AppointmentSlotService;
use App\Services\AstrologerAppointmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use RuntimeException;

class AdminAppointmentController extends Controller
{
    public function index(Request $request, AstrologerAppointmentService $service): JsonResponse
    {
        $group = strtolower((string) $request->query('group', 'waiting_payment'));
        $search = trim((string) $request->query('search', ''));

        $appointments = AstrologerAppointment::query()
            ->with(['user', 'questions', 'appointmentLanguage'])
            ->when($group === 'payment_received', fn ($query) => $query
                ->whereIn('status', ['open', 'pending', 'reviewing', 'slot_proposed'])
                ->whereIn('payment_status', ['PAID', 'NOT_REQUIRED']))
            ->when($group === 'completed', fn ($query) => $query
                ->where('status', 'completed')
                ->whereIn('payment_status', ['PAID', 'NOT_REQUIRED']))
            ->when($group === 'upcoming', fn ($query) => $query
                ->whereIn('status', ['slot_fixed', 'booked', 'upcoming'])
                ->whereIn('payment_status', ['PAID', 'NOT_REQUIRED']))
            ->when(in_array($group, ['cancelled', 'cancelled_failed'], true), fn ($query) => $query
                ->where(function ($inner): void {
                    $inner->where('status', 'cancelled')
                        ->orWhere('payment_status', 'FAILED');
                }))
            ->when(! in_array($group, ['payment_received', 'completed', 'upcoming', 'cancelled', 'cancelled_failed'], true), fn ($query) => $query
                ->whereIn('status', ['open', 'payment_pending', 'pending', 'reviewing', 'slot_proposed'])
                ->where('payment_status', 'PENDING'))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('appointment_number', 'like', "%{$search}%")
                        ->orWhereHas('user', fn ($userQuery) => $userQuery
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%"));
                });
            })
            ->latest()
            ->paginate((int) $request->query('per_page', 25));

        return response()->json([
            'success' => true,
            'data' => collect($appointments->items())->map(fn (AstrologerAppointment $appointment): array => $service->payload($appointment, true))->values(),
            'meta' => [
                'current_page' => $appointments->currentPage(),
                'last_page' => $appointments->lastPage(),
                'total' => $appointments->total(),
            ],
            'counts' => [
                'waiting_payment' => AstrologerAppointment::query()
                    ->whereIn('status', ['open', 'payment_pending', 'pending', 'reviewing', 'slot_proposed'])
                    ->where('payment_status', 'PENDING')
                    ->count(),
                'payment_received' => AstrologerAppointment::query()
                    ->whereIn('status', ['open', 'pending', 'reviewing', 'slot_proposed'])
                    ->whereIn('payment_status', ['PAID', 'NOT_REQUIRED'])
                    ->count(),
                'completed' => AstrologerAppointment::query()
                    ->where('status', 'completed')
                    ->whereIn('payment_status', ['PAID', 'NOT_REQUIRED'])
                    ->count(),
                'upcoming' => AstrologerAppointment::query()
                    ->whereIn('status', ['slot_fixed', 'booked', 'upcoming'])
                    ->whereIn('payment_status', ['PAID', 'NOT_REQUIRED'])
                    ->count(),
                'cancelled' => AstrologerAppointment::query()
                    ->where(function ($query): void {
                        $query->where('status', 'cancelled')
                            ->orWhere('payment_status', 'FAILED');
                    })
                    ->count(),
                'cancelled_failed' => AstrologerAppointment::query()
                    ->where(function ($query): void {
                        $query->where('status', 'cancelled')
                            ->orWhere('payment_status', 'FAILED');
                    })
                    ->count(),
            ],
        ]);
    }

    public function show(AstrologerAppointment $appointment, AstrologerAppointmentService $service): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $service->payload($appointment->load(['user', 'questions', 'activities']), true),
        ]);
    }

    public function availableSlots(Request $request, AppointmentSlotService $slotService): JsonResponse
    {
        $startedAt = microtime(true);
        $shouldProfile = app()->environment(['local', 'development', 'testing']);
        if ($shouldProfile) {
            DB::flushQueryLog();
            DB::enableQueryLog();
        }

        $validated = $request->validate([
            'astrologer_id' => ['required', 'integer', 'exists:astrologers,id'],
            'slot_date' => ['required', 'date_format:Y-m-d'],
            'appointment_id' => ['nullable', 'integer', 'exists:astrologer_appointments,id'],
        ]);

        $appointment = ! empty($validated['appointment_id'])
            ? AstrologerAppointment::query()->findOrFail($validated['appointment_id'])
            : null;

        $data = $slotService->availableSlots((int) $validated['astrologer_id'], $validated['slot_date'], $appointment);
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        if ($shouldProfile && $durationMs >= 1000) {
            Log::info('Slow admin appointment slot load', [
                'route' => 'admin.appointments.available-slots',
                'duration_ms' => $durationMs,
                'query_count' => count(DB::getQueryLog()),
                'astrologer_id' => (int) $validated['astrologer_id'],
                'slot_date' => $validated['slot_date'],
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function monthSlots(Request $request, AppointmentSlotService $slotService): JsonResponse
    {
        $validated = $request->validate([
            'astrologer_id' => ['required', 'integer', 'exists:astrologers,id'],
            'month' => ['required', 'date_format:Y-m'],
            'appointment_id' => ['nullable', 'integer', 'exists:astrologer_appointments,id'],
        ]);

        $appointment = ! empty($validated['appointment_id'])
            ? AstrologerAppointment::query()->find((int) $validated['appointment_id'])
            : null;

        return response()->json([
            'success' => true,
            'data' => $slotService->monthAvailability((int) $validated['astrologer_id'], $validated['month'], $appointment),
        ]);
    }

    public function preferredSlotAvailability(AstrologerAppointment $appointment, Request $request, AppointmentSlotService $slotService): JsonResponse
    {
        $validated = $request->validate([
            'astrologer_id' => ['required', 'integer', 'exists:astrologers,id'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $slotService->preferredSlotAvailability((int) $validated['astrologer_id'], $appointment),
        ]);
    }

    public function slots(Request $request, AstrologerAppointmentService $service): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required_without:slot_date', 'date_format:Y-m-d'],
            'slot_date' => ['required_without:date', 'date_format:Y-m-d'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'astrologer_id' => ['nullable', 'integer', 'exists:astrologers,id'],
        ]);
        $date = $validated['slot_date'] ?? $validated['date'];

        return response()->json([
            'success' => true,
            'data' => $service->availableSlots($date, null, $validated['astrologer_id'] ?? null),
        ]);
    }

    public function confirmSlot(Request $request, AstrologerAppointment $appointment, AstrologerAppointmentService $service): JsonResponse
    {
        $validated = $request->validate([
            'astrologer_id' => ['required', 'integer', 'exists:astrologers,id'],
            'slot_start' => ['required', 'date'],
            'meeting_provider' => ['nullable', 'string', 'max:32'],
            'meeting_link' => ['nullable', 'url', 'max:2048'],
            'admin_note' => ['nullable', 'string', 'max:4000'],
        ]);

        try {
            $appointment = $service->confirmSlot($appointment, $request->user(), [
                'astrologer_id' => $validated['astrologer_id'],
                'confirmed_start_at' => $validated['slot_start'],
                'meeting_provider' => $validated['meeting_provider'] ?? null,
                'meeting_link' => $validated['meeting_link'] ?? null,
                'admin_note' => $validated['admin_note'] ?? null,
            ]);
        } catch (RuntimeException $exception) {
            return response()->json([
                'success' => false,
                'code' => $exception->getMessage() === 'The selected slot is no longer available.'
                    ? AppointmentSlotService::SLOT_NO_LONGER_AVAILABLE
                    : null,
                'message' => $exception->getMessage(),
            ], 409);
        }

        return response()->json([
            'success' => true,
            'message' => 'Appointment slot confirmed.',
            'data' => $service->payload($appointment, true),
        ]);
    }

    public function update(Request $request, AstrologerAppointment $appointment, AstrologerAppointmentService $service): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['open', 'slot_fixed', 'pending', 'reviewing', 'slot_proposed', 'payment_pending', 'booked', 'upcoming', 'completed', 'cancelled'])],
            'payment_status' => ['nullable', Rule::in(['PENDING', 'PAID', 'FAILED'])],
            'confirmed_start_at' => ['nullable', 'date'],
            'astrologer_id' => ['nullable', 'integer', 'exists:astrologers,id'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'meeting_provider' => ['nullable', 'string', 'max:32'],
            'meeting_link' => ['nullable', 'url', 'max:2048'],
            'admin_note' => ['nullable', 'string', 'max:4000'],
        ]);

        try {
            if (($validated['payment_status'] ?? null) === 'PAID' && $appointment->payment_status !== 'PAID') {
                if ($appointment->payment_status === 'FAILED') {
                    return response()->json([
                        'success' => false,
                        'message' => 'Payment failed requests cannot be manually changed to payment received.',
                    ], 409);
                }

                if ($service->appointmentPaymentMethod($appointment) !== AppointmentPaymentMethod::QR_ONLINE) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Only QR / Online appointments can be marked payment received by admin.',
                    ], 409);
                }

                $service->markPaid($appointment);
                $appointment = $appointment->refresh();
            }

            if (($validated['payment_status'] ?? null) === 'FAILED' && $appointment->payment_status !== 'FAILED') {
                if ($appointment->payment_status === 'PAID') {
                    return response()->json([
                        'success' => false,
                        'message' => 'Paid appointments cannot be rejected.',
                    ], 409);
                }

                if ($service->appointmentPaymentMethod($appointment) !== AppointmentPaymentMethod::QR_ONLINE) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Only QR / Online appointments can be rejected manually.',
                    ], 409);
                }

                if (array_key_exists('admin_note', $validated)) {
                    $appointment->forceFill(['admin_note' => $validated['admin_note']])->save();
                }

                $service->markPaymentFailed($appointment, 'admin_rejected', $validated['admin_note'] ?? null);
                $appointment = $appointment->refresh();
            }

            if (! empty($validated['confirmed_start_at'])) {
                $appointment = $service->confirmSlot($appointment, $request->user(), $validated);
            } else {
                $updates = array_filter([
                    'status' => $validated['status'] ?? null,
                    'meeting_provider' => $validated['meeting_provider'] ?? null,
                    'meeting_link' => $validated['meeting_link'] ?? null,
                    'admin_note' => $validated['admin_note'] ?? null,
                ], fn ($value) => $value !== null);

                if (($updates['status'] ?? null) === 'cancelled') {
                    if (array_key_exists('admin_note', $validated)) {
                        $appointment->forceFill(['admin_note' => $validated['admin_note']])->save();
                    }
                    $appointment = $service->cancel($appointment, admin: $request->user());
                } else {
                    if (($updates['status'] ?? null) === 'completed') {
                    $updates['completed_at'] = now();
                    }

                    $appointment->forceFill($updates)->save();
                    $service->record($appointment, 'admin_updated', 'Appointment updated by admin.', admin: $request->user(), payload: $updates);

                    if ($updates !== []) {
                        Mail::raw(
                            "Your consultation {$appointment->appointment_number} has been updated.\nStatus: ".ucwords(str_replace('_', ' ', $appointment->status))."\nMeeting: ".($appointment->meeting_link ?: 'Meeting details will be shared soon.'),
                            fn ($message) => $message->to($appointment->user?->email)->subject('Your astrologer consultation was updated')
                        );
                    }

                    $appointment = $appointment->refresh()->load(['user', 'questions', 'activities']);
                }
            }
        } catch (RuntimeException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 409);
        }

        return response()->json([
            'success' => true,
            'message' => 'Appointment updated.',
            'data' => $service->payload($appointment, true),
        ]);
    }

    public function manualPaymentFile(Request $request, AstrologerAppointment $appointment)
    {
        $manualPayment = data_get($appointment->metadata ?? [], 'manual_payment');
        $path = is_array($manualPayment) ? data_get($manualPayment, 'path') : null;

        if (! $path) {
            return response()->json(['success' => false, 'message' => 'Manual payment proof is not available.'], 404);
        }

        $disk = Storage::disk('local');
        if (! $disk->exists($path)) {
            return response()->json(['success' => false, 'message' => 'Manual payment proof is unavailable.'], 404);
        }

        return response()->file($disk->path($path), array_filter([
            'Content-Type' => data_get($manualPayment, 'mime_type'),
        ]));
    }
}
