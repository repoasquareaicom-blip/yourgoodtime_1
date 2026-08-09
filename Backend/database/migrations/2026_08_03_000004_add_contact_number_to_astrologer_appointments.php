<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('astrologer_appointments') && ! Schema::hasColumn('astrologer_appointments', 'contact_number')) {
            Schema::table('astrologer_appointments', function (Blueprint $table): void {
                $table->string('contact_number', 32)->nullable()->after('notes');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('astrologer_appointments') && Schema::hasColumn('astrologer_appointments', 'contact_number')) {
            Schema::table('astrologer_appointments', function (Blueprint $table): void {
                $table->dropColumn('contact_number');
            });
        }
    }
};
