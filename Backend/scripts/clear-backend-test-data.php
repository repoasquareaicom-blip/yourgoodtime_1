<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$force = in_array('--force', $argv, true);

$tables = [
    'personal_access_tokens',
    'sessions',
    'password_reset_tokens',
    'otp_records',
    'cache',
    'cache_locks',
    'jobs',
    'job_batches',
    'failed_jobs',
    'astrology_api_logs',
    'astrology_sessions',
    'prediction_feedback',
    'prediction_entitlements',
    'prediction_results',
    'prediction_rule_logs',
    'payment_events',
    'payment_transactions',
    'astrologer_appointment_activities',
    'astrologer_appointment_questions',
    'astrologer_appointment_slot_blocks',
    'astrologer_appointments',
    'temple_remedy_requests',
    'prediction_requests',
    'users',
];

function existingTables(array $tables): array
{
    return array_values(array_filter($tables, fn (string $table): bool => Schema::hasTable($table)));
}

function tableCounts(array $tables): array
{
    $counts = [];

    foreach (existingTables($tables) as $table) {
        $counts[$table] = DB::table($table)->count();
    }

    return $counts;
}

function deleteIfExists(string $table): void
{
    if (Schema::hasTable($table)) {
        DB::table($table)->delete();
    }
}

$before = tableCounts($tables);

if (! $force) {
    echo "Dry run only. No data was deleted.\n";
    echo "This script clears customer/test runtime data for fresh signup testing.\n";
    echo "Preserved: admin_users, prediction_products, astrologers, consultation questions, appointment languages/payment methods, migrations, config.\n";
    echo 'Current counts: '.json_encode($before)."\n";
    echo "Run with --force to clear these tables.\n";
    exit(0);
}

DB::transaction(function (): void {
    if (Schema::hasTable('prediction_entitlements') && Schema::hasColumn('prediction_entitlements', 'generated_prediction_result_id')) {
        DB::table('prediction_entitlements')->update(['generated_prediction_result_id' => null]);
    }

    if (Schema::hasTable('prediction_results') && Schema::hasColumn('prediction_results', 'prediction_entitlement_id')) {
        DB::table('prediction_results')->update(['prediction_entitlement_id' => null]);
    }

    deleteIfExists('personal_access_tokens');
    deleteIfExists('sessions');
    deleteIfExists('password_reset_tokens');
    deleteIfExists('otp_records');
    deleteIfExists('cache');
    deleteIfExists('cache_locks');
    deleteIfExists('jobs');
    deleteIfExists('job_batches');
    deleteIfExists('failed_jobs');

    deleteIfExists('payment_events');
    deleteIfExists('prediction_feedback');
    deleteIfExists('prediction_entitlements');
    deleteIfExists('prediction_results');
    deleteIfExists('prediction_rule_logs');
    deleteIfExists('payment_transactions');

    deleteIfExists('astrologer_appointment_activities');
    deleteIfExists('astrologer_appointment_questions');
    deleteIfExists('astrologer_appointment_slot_blocks');
    deleteIfExists('astrologer_appointments');
    deleteIfExists('temple_remedy_requests');

    deleteIfExists('astrology_api_logs');
    deleteIfExists('astrology_sessions');
    deleteIfExists('prediction_requests');
    deleteIfExists('users');
});

$after = tableCounts($tables);

echo "Backend test data cleared.\n";
echo 'Before: '.json_encode($before)."\n";
echo 'After:  '.json_encode($after)."\n";
