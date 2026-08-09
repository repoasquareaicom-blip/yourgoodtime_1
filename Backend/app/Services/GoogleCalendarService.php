<?php

namespace App\Services;

use App\Models\AstrologerAppointment;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class GoogleCalendarService
{
    private const SCOPE = 'https://www.googleapis.com/auth/calendar.events';

    public function configured(): bool
    {
        return (string) config('services.google_calendar.calendar_id') !== ''
            && $this->credentials() !== null;
    }

    public function upsertAppointmentEvent(AstrologerAppointment $appointment): ?array
    {
        if (! $this->configured()) {
            return null;
        }

        if (! $appointment->confirmed_start_at || ! $appointment->confirmed_end_at) {
            return null;
        }

        $appointment->loadMissing(['user', 'questions']);
        $calendarId = rawurlencode((string) config('services.google_calendar.calendar_id'));
        $eventId = (string) $appointment->calendar_event_id;
        $isExistingEvent = $eventId !== '' && ! str_starts_with($eventId, 'pending-calendar-');
        $payload = $this->eventPayload($appointment);
        $query = http_build_query([
            'sendUpdates' => 'all',
            'conferenceDataVersion' => $appointment->meeting_provider === 'google_meet' ? 1 : 0,
        ]);

        $request = Http::withToken($this->accessToken())
            ->acceptJson()
            ->asJson();

        $response = $isExistingEvent
            ? $request->patch("https://www.googleapis.com/calendar/v3/calendars/{$calendarId}/events/{$eventId}?{$query}", $payload)
            : $request->post("https://www.googleapis.com/calendar/v3/calendars/{$calendarId}/events?{$query}", $payload);

        if ($response->failed()) {
            throw new RuntimeException('Google Calendar event could not be saved.');
        }

        return $response->json();
    }

    public function cancelAppointmentEvent(AstrologerAppointment $appointment): void
    {
        if (! $this->configured() || ! $appointment->calendar_event_id || str_starts_with($appointment->calendar_event_id, 'pending-calendar-')) {
            return;
        }

        $calendarId = rawurlencode((string) config('services.google_calendar.calendar_id'));
        $eventId = rawurlencode((string) $appointment->calendar_event_id);

        Http::withToken($this->accessToken())
            ->acceptJson()
            ->delete("https://www.googleapis.com/calendar/v3/calendars/{$calendarId}/events/{$eventId}", [
                'sendUpdates' => 'all',
            ])
            ->throw();
    }

    private function eventPayload(AstrologerAppointment $appointment): array
    {
        $timezone = $appointment->confirmed_timezone ?: (string) config('services.google_calendar.timezone', 'Asia/Kolkata');
        $questions = $appointment->questions
            ->map(fn ($item): string => "{$item->sequence}. {$item->question}")
            ->implode("\n");

        $payload = [
            'summary' => "Your Good Time Consultation {$appointment->appointment_number}",
            'description' => trim("Appointment: {$appointment->appointment_number}\n\nQuestions:\n{$questions}\n\nNotes:\n".($appointment->notes ?: '-')),
            'start' => $this->dateTimePayload($appointment->confirmed_start_at, $timezone),
            'end' => $this->dateTimePayload($appointment->confirmed_end_at, $timezone),
            'attendees' => array_values(array_filter([
                $appointment->user?->email ? ['email' => $appointment->user->email, 'displayName' => $appointment->user->name] : null,
            ])),
        ];

        if ($appointment->meeting_provider === 'google_meet' && ! $appointment->meeting_link) {
            $payload['conferenceData'] = [
                'createRequest' => [
                    'requestId' => 'ygt-'.$appointment->id.'-'.Str::lower(Str::random(8)),
                    'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
                ],
            ];
        }

        if ($appointment->meeting_link && $appointment->meeting_provider !== 'google_meet') {
            $payload['location'] = $appointment->meeting_link;
        }

        return $payload;
    }

    private function dateTimePayload(CarbonInterface $dateTime, string $timezone): array
    {
        return [
            'dateTime' => $dateTime->copy()->timezone($timezone)->toRfc3339String(),
            'timeZone' => $timezone,
        ];
    }

    private function accessToken(): string
    {
        $credentials = $this->credentials();
        if ($credentials === null) {
            throw new RuntimeException('Google Calendar credentials are not configured.');
        }

        $now = time();
        $claims = [
            'iss' => $credentials['client_email'],
            'scope' => self::SCOPE,
            'aud' => $credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        if ((string) config('services.google_calendar.delegated_user') !== '') {
            $claims['sub'] = (string) config('services.google_calendar.delegated_user');
        }

        $jwt = $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']))
            .'.'.$this->base64Url(json_encode($claims));

        openssl_sign($jwt, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256);
        $jwt .= '.'.$this->base64Url($signature);

        $response = Http::asForm()
            ->post($claims['aud'], [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ])
            ->throw()
            ->json();

        return (string) $response['access_token'];
    }

    private function credentials(): ?array
    {
        $raw = (string) config('services.google_calendar.service_account_json');
        $path = (string) config('services.google_calendar.service_account_path');

        if ($raw === '' && $path !== '' && is_file($path)) {
            $raw = (string) file_get_contents($path);
        }

        if ($raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            $decoded = json_decode((string) base64_decode($raw, true), true);
        }

        if (! is_array($decoded) || empty($decoded['client_email']) || empty($decoded['private_key'])) {
            return null;
        }

        return $decoded;
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
