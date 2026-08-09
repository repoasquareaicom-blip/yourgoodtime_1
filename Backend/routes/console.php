<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use App\Models\AdminUser;
use App\Models\AstrologerAppointment;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('ygt:admin {email} {--name=} {--password=}', function (string $email): int {
    $normalizedEmail = strtolower(trim($email));
    $password = (string) $this->option('password');

    $admin = AdminUser::query()->firstOrNew(['email' => $normalizedEmail]);

    if (! $admin->exists) {
        if ($password === '') {
            $password = bin2hex(random_bytes(8));
            $this->warn("Temporary password: {$password}");
            $this->line('You can also use forgot password for this email after login setup.');
        }

        $admin->name = (string) ($this->option('name') ?: strtok($normalizedEmail, '@'));
        $admin->password = Hash::make($password);
        $admin->email_verified_at = now();
    } elseif ($password !== '') {
        $admin->password = Hash::make($password);
    }

    $admin->is_active = true;
    $admin->save();

    $this->info("Admin access enabled for {$normalizedEmail}.");

    return 0;
})->purpose('Create or promote a Your Good Time admin user');

Artisan::command('appointments:send-reminders', function (): int {
    $now = now('Asia/Kolkata');
    $windows = [
        ['field' => 'reminder_24h_sent_at', 'hours' => 24, 'label' => '24 hours'],
        ['field' => 'reminder_1h_sent_at', 'hours' => 1, 'label' => '1 hour'],
    ];

    foreach ($windows as $window) {
        AstrologerAppointment::query()
            ->with('user')
            ->whereIn('status', ['booked', 'upcoming'])
            ->whereNull($window['field'])
            ->whereBetween('confirmed_start_at', [$now->toDateTimeString(), $now->copy()->addHours($window['hours'])->toDateTimeString()])
            ->chunkById(50, function ($appointments) use ($window): void {
                foreach ($appointments as $appointment) {
                    Mail::raw(
                        "Reminder: your consultation {$appointment->appointment_number} starts in {$window['label']}.\nMeeting: ".($appointment->meeting_link ?: 'Meeting details will be shared soon.'),
                        fn ($message) => $message->to($appointment->user?->email)->subject('Consultation reminder')
                    );
                    $appointment->forceFill([$window['field'] => now()])->save();
                    $appointment->activities()->create([
                        'event_type' => 'reminder_sent',
                        'title' => "Reminder sent for {$window['label']} before appointment.",
                        'payload' => ['window' => $window['label']],
                    ]);
                }
            });
    }

    $this->info('Appointment reminders processed.');

    return 0;
})->purpose('Send astrologer appointment reminder emails');

Schedule::command('appointments:send-reminders')->everyFifteenMinutes();
