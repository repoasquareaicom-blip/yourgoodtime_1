<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use App\Models\AdminUser;

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
