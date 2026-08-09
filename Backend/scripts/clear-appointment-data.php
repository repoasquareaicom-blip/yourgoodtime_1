<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$before = [
    'appointments' => DB::table('astrologer_appointments')->count(),
    'appointment_questions' => DB::table('astrologer_appointment_questions')->count(),
    'appointment_activities' => DB::table('astrologer_appointment_activities')->count(),
    'linked_payment_transactions' => DB::table('payment_transactions')->whereNotNull('astrologer_appointment_id')->count(),
];

DB::transaction(function (): void {
    DB::table('payment_transactions')
        ->whereNotNull('astrologer_appointment_id')
        ->update(['astrologer_appointment_id' => null]);

    DB::table('astrologer_appointment_activities')->delete();
    DB::table('astrologer_appointment_questions')->delete();
    DB::table('astrologer_appointments')->delete();
});

$after = [
    'appointments' => DB::table('astrologer_appointments')->count(),
    'appointment_questions' => DB::table('astrologer_appointment_questions')->count(),
    'appointment_activities' => DB::table('astrologer_appointment_activities')->count(),
    'linked_payment_transactions' => DB::table('payment_transactions')->whereNotNull('astrologer_appointment_id')->count(),
];

echo "Appointment data cleared.\n";
echo 'Before: '.json_encode($before)."\n";
echo 'After:  '.json_encode($after)."\n";
