<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table): void {
            if (! Schema::hasColumn('payment_transactions', 'astrologer_appointment_id')) {
                $table->foreignId('astrologer_appointment_id')
                    ->nullable()
                    ->after('temple_remedy_request_id')
                    ->constrained('astrologer_appointments')
                    ->nullOnDelete();

                $table->index('astrologer_appointment_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table): void {
            if (Schema::hasColumn('payment_transactions', 'astrologer_appointment_id')) {
                $table->dropConstrainedForeignId('astrologer_appointment_id');
            }
        });
    }
};
