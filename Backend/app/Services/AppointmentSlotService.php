<?php

namespace App\Services;

use App\Models\Astrologer;
use App\Models\AstrologerAppointment;
use App\Models\AstrologerAppointmentSlotBlock;
use App\Models\PredictionProduct;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use RuntimeException;

class AppointmentSlotService
{
    public const SLOT_NO_LONGER_AVAILABLE = 'SLOT_NO_LONGER_AVAILABLE';

    public function availableSlots(int $astrologerId, string $slotDate, ?AstrologerAppointment $appointment = null): array
    {
        $astrologer = $this->activeAstrologer($astrologerId);
        $schedule = $this->resolveSchedule($astrologer, $appointment);
        $date = CarbonImmutable::parse($slotDate, $schedule['timezone'])->toDateString();
        $day = CarbonImmutable::parse($date, $schedule['timezone'])->startOfDay();
        $blockedDayReason = $this->blockedDayReason($day, $schedule);

        $cursor = $this->timeOnDay($day, $schedule['working_start_time']);
        $workingEnd = $this->timeOnDay($day, $schedule['working_end_time']);
        if ($workingEnd->lte($cursor)) {
            $workingEnd = $workingEnd->addDay();
        }

        $slots = [];
        $now = CarbonImmutable::now($schedule['timezone']);
        $dateStart = $day->toDateTimeString();
        $dateEnd = $workingEnd->toDateTimeString();
        $dateStartUtc = $day->utc()->toDateTimeString();
        $dateEndUtc = $workingEnd->utc()->toDateTimeString();
        $reservations = $this->dayReservations($astrologer->id, $dateStart, $dateEnd);
        $blocks = $this->dayBlocks($astrologer->id, $dateStartUtc, $dateEndUtc);
        $slots = $this->slotsForDay($day, $workingEnd, $schedule, $reservations, $blocks, $appointment, $blockedDayReason, $now);

        return [
            'astrologer_id' => $astrologer->id,
            'slot_date' => $date,
            'timezone' => $schedule['timezone'],
            'working_hours' => [
                'start' => $schedule['working_start_time'],
                'end' => $schedule['working_end_time'],
            ],
            'slot_duration_minutes' => $schedule['duration_minutes'],
            'break_duration_minutes' => $schedule['break_minutes'],
            'summary' => [
                'available' => collect($slots)->where('status', 'available')->count(),
                'reserved' => collect($slots)->where('status', 'reserved')->count(),
                'selected' => collect($slots)->where('status', 'selected')->count(),
                'blocked' => collect($slots)->where('status', 'blocked')->count(),
            ],
            'slots' => $slots,
        ];
    }

    public function preferredSlotAvailability(int $astrologerId, AstrologerAppointment $appointment): array
    {
        $astrologer = $this->activeAstrologer($astrologerId);
        $schedule = $this->resolveSchedule($astrologer, $appointment);

        if (! $appointment->preferred_date || ! $appointment->preferred_time) {
            return [
                'available' => false,
                'reason' => 'Preferred date or time is missing.',
                'timezone' => $schedule['timezone'],
            ];
        }

        $date = $appointment->preferred_date->toDateString();
        $day = CarbonImmutable::parse($date, $schedule['timezone'])->startOfDay();
        $start = CarbonImmutable::parse($date.' '.$appointment->preferred_time, $schedule['timezone']);
        $end = $start->addMinutes($schedule['duration_minutes']);
        $workingStart = $this->timeOnDay($day, $schedule['working_start_time']);
        $workingEnd = $this->timeOnDay($day, $schedule['working_end_time']);
        if ($workingEnd->lte($workingStart)) {
            $workingEnd = $workingEnd->addDay();
        }

        $reason = $this->blockedDayReason($day, $schedule);
        if (! $reason && ($start->lt($workingStart) || $end->gt($workingEnd))) {
            $reason = 'Outside working hours';
        }

        if (! $reason && $start->lte(CarbonImmutable::now($schedule['timezone']))) {
            $reason = 'Past slot';
        }

        if (! $reason && $this->overlappingReservation($astrologer->id, $start, $end, $appointment->id)) {
            $reason = 'Reserved';
        }

        if (! $reason && $this->overlappingBlock($astrologer->id, $start, $end)) {
            $reason = 'Blocked';
        }

        return [
            'available' => $reason === null,
            'reason' => $reason,
            'astrologer_id' => $astrologer->id,
            'slot_date' => $date,
            'timezone' => $schedule['timezone'],
            'start' => $start->toIso8601String(),
            'end' => $end->toIso8601String(),
            'start_label' => $start->format('h:i A'),
            'end_label' => $end->format('h:i A'),
        ];
    }

    public function monthAvailability(int $astrologerId, string $month, ?AstrologerAppointment $appointment = null): array
    {
        $astrologer = $this->activeAstrologer($astrologerId);
        $schedule = $this->resolveSchedule($astrologer, $appointment);
        $start = CarbonImmutable::parse($month.'-01', $schedule['timezone'])->startOfMonth();
        $end = $start->endOfMonth();
        $days = [];
        $monthEndWorking = $this->timeOnDay($end, $schedule['working_end_time']);
        if ($monthEndWorking->lte($this->timeOnDay($end, $schedule['working_start_time']))) {
            $monthEndWorking = $monthEndWorking->addDay();
        }
        $reservations = $this->dayReservations($astrologer->id, $start->toDateTimeString(), $monthEndWorking->toDateTimeString());
        $blocks = $this->dayBlocks($astrologer->id, $start->utc()->toDateTimeString(), $monthEndWorking->utc()->toDateTimeString());
        $now = CarbonImmutable::now($schedule['timezone']);

        for ($day = $start; $day->lte($end); $day = $day->addDay()) {
            $workingEnd = $this->timeOnDay($day, $schedule['working_end_time']);
            if ($workingEnd->lte($this->timeOnDay($day, $schedule['working_start_time']))) {
                $workingEnd = $workingEnd->addDay();
            }
            $slots = $this->slotsForDay(
                $day,
                $workingEnd,
                $schedule,
                $reservations,
                $blocks,
                $appointment,
                $this->blockedDayReason($day, $schedule),
                $now
            );
            $summary = collect($slots)->countBy('status');
            $days[] = [
                'date' => $day->toDateString(),
                'available' => $summary->get('available', 0),
                'reserved' => $summary->get('reserved', 0),
                'blocked' => $summary->get('blocked', 0),
                'selected' => $summary->get('selected', 0),
                'total' => count($slots),
            ];
        }

        return [
            'astrologer_id' => $astrologer->id,
            'month' => $start->format('Y-m'),
            'timezone' => $schedule['timezone'],
            'slot_duration_minutes' => $schedule['duration_minutes'],
            'break_duration_minutes' => $schedule['break_minutes'],
            'days' => $days,
        ];
    }

    public function assertSlotAvailable(int $astrologerId, string $slotStart, AstrologerAppointment $appointment): array
    {
        $astrologer = $this->activeAstrologer($astrologerId);
        $schedule = $this->resolveSchedule($astrologer, $appointment);
        $start = CarbonImmutable::parse($slotStart)->timezone($schedule['timezone']);
        $date = $start->toDateString();
        $availability = $this->availableSlots($astrologer->id, $date, $appointment);
        $slot = collect($availability['slots'])->first(fn (array $item): bool => CarbonImmutable::parse($item['start'])->getTimestamp() === $start->getTimestamp());

        if (! $slot || ! in_array($slot['status'], ['available', 'selected'], true)) {
            throw new RuntimeException('The selected slot is no longer available.');
        }

        $end = $start->addMinutes($availability['slot_duration_minutes']);

        AstrologerAppointment::query()
            ->whereIn('status', $this->activeReservationStatuses())
            ->where(function ($query) use ($astrologerId): void {
                $query->whereNull('astrologer_id')->orWhere('astrologer_id', $astrologerId);
            })
            ->whereKeyNot($appointment->id)
            ->whereNotNull('confirmed_start_at')
            ->where('confirmed_start_at', '<', $end->toDateTimeString())
            ->where('confirmed_end_at', '>', $start->toDateTimeString())
            ->lockForUpdate()
            ->get();

        if ($this->overlappingReservation($astrologer->id, $start, $end, $appointment->id)) {
            throw new RuntimeException('The selected slot is no longer available.');
        }

        if ($this->overlappingBlock($astrologer->id, $start, $end)) {
            throw new RuntimeException('The selected slot is no longer available.');
        }

        return [
            'astrologer' => $astrologer,
            'timezone' => $availability['timezone'],
            'start' => $start,
            'end' => $end,
            'duration_minutes' => $availability['slot_duration_minutes'],
        ];
    }

    public function resolveSchedule(Astrologer $astrologer, ?AstrologerAppointment $appointment = null): array
    {
        $productMetadata = $this->appointmentProductMetadata();
        $astrologerMetadata = $astrologer->metadata ?? [];
        $timezone = $astrologer->timezone
            ?: $appointment?->confirmed_timezone
            ?: $appointment?->preferred_timezone
            ?: data_get($productMetadata, 'business_timezone')
            ?: config('appointment.business_timezone', 'Asia/Kolkata');

        return [
            'timezone' => $timezone,
            'working_days' => data_get($astrologerMetadata, 'working_days', data_get($productMetadata, 'working_days', config('appointment.working_days', [1, 2, 3, 4, 5, 6, 7]))),
            'working_start_time' => data_get($astrologerMetadata, 'working_start_time', data_get($productMetadata, 'working_start_time', config('appointment.working_start_time', '00:00'))),
            'working_end_time' => data_get($astrologerMetadata, 'working_end_time', data_get($productMetadata, 'working_end_time', config('appointment.working_end_time', '00:00'))),
            'duration_minutes' => (int) data_get($astrologerMetadata, 'duration_minutes', data_get($productMetadata, 'duration_minutes', config('appointment.default_duration_minutes', 20))),
            'break_minutes' => (int) data_get($astrologerMetadata, 'break_minutes', data_get($astrologerMetadata, 'buffer_minutes', data_get($productMetadata, 'break_minutes', data_get($productMetadata, 'buffer_minutes', config('appointment.default_break_minutes', 10))))),
            'holidays' => data_get($astrologerMetadata, 'holidays', data_get($productMetadata, 'holidays', [])),
            'leave_dates' => data_get($astrologerMetadata, 'leave_dates', data_get($productMetadata, 'leave_dates', [])),
            'blocked_dates' => data_get($astrologerMetadata, 'blocked_dates', data_get($productMetadata, 'blocked_dates', [])),
        ];
    }

    public function activeReservationStatuses(): array
    {
        return ['slot_fixed', 'slot_proposed', 'payment_pending', 'booked', 'upcoming'];
    }

    private function activeAstrologer(int $astrologerId): Astrologer
    {
        return Astrologer::query()
            ->whereKey($astrologerId)
            ->where('is_active', true)
            ->firstOrFail();
    }

    private function appointmentProductMetadata(): array
    {
        $product = PredictionProduct::query()
            ->where('code', AstrologerAppointmentService::PRODUCT_CODE)
            ->where('is_active', true)
            ->first();

        return $product?->metadata ?? [];
    }

    private function blockedDayReason(CarbonImmutable $day, array $schedule): ?string
    {
        $date = $day->toDateString();

        if (! in_array((int) $day->dayOfWeekIso, $schedule['working_days'], true)) {
            return 'Non-working day';
        }

        if (in_array($date, $schedule['holidays'], true)) {
            return 'Holiday';
        }

        if (in_array($date, $schedule['leave_dates'], true)) {
            return 'Astrologer leave';
        }

        if (in_array($date, $schedule['blocked_dates'], true)) {
            return 'Blocked date';
        }

        return null;
    }

    private function slotState(CarbonImmutable $start, ?AstrologerAppointment $reservation, ?AstrologerAppointmentSlotBlock $block, ?string $blockedDayReason, ?AstrologerAppointment $appointment): array
    {
        if ($blockedDayReason) {
            return ['blocked', $blockedDayReason, null, false];
        }

        if ($block) {
            return ['blocked', $block->reason ?: 'Blocked', null, false];
        }

        if ($reservation) {
            if ($appointment && $reservation->id === $appointment->id) {
                return ['selected', null, $reservation->id, true];
            }

            return ['reserved', null, $reservation->id, false];
        }

        return ['available', null, null, false];
    }

    private function overlappingReservation(int $astrologerId, CarbonImmutable $start, CarbonImmutable $end, ?int $exceptAppointmentId = null): ?AstrologerAppointment
    {
        return AstrologerAppointment::query()
            ->whereIn('status', $this->activeReservationStatuses())
            ->where(function ($query) use ($astrologerId): void {
                $query->whereNull('astrologer_id')->orWhere('astrologer_id', $astrologerId);
            })
            ->when($exceptAppointmentId, fn ($query) => $query->whereKeyNot($exceptAppointmentId))
            ->whereNotNull('confirmed_start_at')
            ->where('confirmed_start_at', '<', $end->toDateTimeString())
            ->where('confirmed_end_at', '>', $start->toDateTimeString())
            ->first();
    }

    private function dayReservations(int $astrologerId, string $dateStart, string $dateEnd): Collection
    {
        return AstrologerAppointment::query()
            ->whereIn('status', $this->activeReservationStatuses())
            ->where(function ($query) use ($astrologerId): void {
                $query->whereNull('astrologer_id')->orWhere('astrologer_id', $astrologerId);
            })
            ->whereNotNull('confirmed_start_at')
            ->where('confirmed_start_at', '<', $dateEnd)
            ->where('confirmed_end_at', '>', $dateStart)
            ->get(['id', 'astrologer_id', 'confirmed_start_at', 'confirmed_end_at', 'confirmed_timezone']);
    }

    private function overlappingBlock(int $astrologerId, CarbonImmutable $start, CarbonImmutable $end): ?AstrologerAppointmentSlotBlock
    {
        return AstrologerAppointmentSlotBlock::query()
            ->where(function ($query) use ($astrologerId): void {
                $query->whereNull('astrologer_id')->orWhere('astrologer_id', $astrologerId);
            })
            ->where('start_at', '<', $end->utc())
            ->where('end_at', '>', $start->utc())
            ->first();
    }

    private function dayBlocks(int $astrologerId, string $dateStartUtc, string $dateEndUtc): Collection
    {
        return AstrologerAppointmentSlotBlock::query()
            ->where(function ($query) use ($astrologerId): void {
                $query->whereNull('astrologer_id')->orWhere('astrologer_id', $astrologerId);
            })
            ->where('start_at', '<', $dateEndUtc)
            ->where('end_at', '>', $dateStartUtc)
            ->get(['id', 'astrologer_id', 'start_at', 'end_at', 'reason']);
    }

    private function slotsForDay(
        CarbonImmutable $day,
        CarbonImmutable $workingEnd,
        array $schedule,
        Collection $reservations,
        Collection $blocks,
        ?AstrologerAppointment $appointment,
        ?string $blockedDayReason,
        CarbonImmutable $now
    ): array {
        $slots = [];
        $cursor = $this->timeOnDay($day, $schedule['working_start_time']);

        while ($cursor->addMinutes($schedule['duration_minutes'])->lte($workingEnd)) {
            $end = $cursor->addMinutes($schedule['duration_minutes']);
            $reservation = $this->firstOverlappingInterval($reservations, $cursor, $end);
            $block = $this->firstOverlappingInterval($blocks, $cursor, $end);
            [$status, $blockedReason, $reservedAppointmentId, $isCurrentAppointmentSlot] = $this->slotState($cursor, $reservation, $block, $blockedDayReason, $appointment);
            $isUserPreferred = $this->isUserPreferredSlot($appointment, $cursor, $schedule['timezone']);

            if ($cursor->lte($now) && $status === 'available') {
                $status = 'blocked';
                $blockedReason = 'Past slot';
            }

            $slots[] = [
                'id' => $cursor->toIso8601String(),
                'key' => $cursor->toIso8601String(),
                'start' => $cursor->toIso8601String(),
                'end' => $end->toIso8601String(),
                'start_label' => $cursor->format('h:i A'),
                'end_label' => $end->format('h:i A'),
                'status' => $status,
                'appointment_id' => $reservedAppointmentId,
                'blocked_reason' => $blockedReason,
                'is_current_appointment_slot' => $isCurrentAppointmentSlot,
                'is_user_preferred' => $isUserPreferred,
                'period' => $this->slotPeriod($cursor),
                'label' => $cursor->format('h:i A'),
                'range_label' => $cursor->format('h:i A').' - '.$end->format('h:i A'),
            ];

            $cursor = $end->addMinutes($schedule['break_minutes']);
        }

        return $slots;
    }

    private function firstOverlappingInterval(Collection $items, CarbonImmutable $start, CarbonImmutable $end): mixed
    {
        return $items->first(function ($item) use ($start, $end): bool {
            $itemStart = $item instanceof AstrologerAppointment
                ? $item->getRawOriginal('confirmed_start_at')
                : $item->getRawOriginal('start_at');
            $itemEnd = $item instanceof AstrologerAppointment
                ? $item->getRawOriginal('confirmed_end_at')
                : $item->getRawOriginal('end_at');

            $itemTimezone = $item instanceof AstrologerAppointment
                ? ($item->confirmed_timezone ?: 'Asia/Kolkata')
                : 'UTC';
            $compareEnd = $item instanceof AstrologerAppointment ? $end : $end->utc();
            $compareStart = $item instanceof AstrologerAppointment ? $start : $start->utc();

            return CarbonImmutable::parse($itemStart, $itemTimezone)->lt($compareEnd)
                && CarbonImmutable::parse($itemEnd, $itemTimezone)->gt($compareStart);
        });
    }

    private function isUserPreferredSlot(?AstrologerAppointment $appointment, CarbonImmutable $start, string $timezone): bool
    {
        if (! $appointment?->preferred_date || ! $appointment->preferred_time) {
            return false;
        }

        $preferred = CarbonImmutable::parse(
            $appointment->preferred_date->toDateString().' '.$appointment->preferred_time,
            $appointment->preferred_timezone ?: $timezone
        )->timezone($timezone);

        return $preferred->getTimestamp() === $start->getTimestamp();
    }

    private function timeOnDay(CarbonImmutable $day, string $time): CarbonImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return $day->setTime($hour, $minute);
    }

    private function slotPeriod(CarbonImmutable $time): string
    {
        $hour = (int) $time->format('G');

        return $hour >= 9 && $hour < 21 ? 'day' : 'night';
    }
}
