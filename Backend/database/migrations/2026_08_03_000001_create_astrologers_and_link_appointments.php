<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('astrologers')) {
            Schema::create('astrologers', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('display_name')->nullable();
                $table->string('timezone', 64)->default('Asia/Kolkata');
                $table->boolean('is_active')->default(true);
                $table->unsignedSmallInteger('sort_order')->default(1);
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['is_active', 'sort_order']);
            });
        }

        DB::table('astrologers')->updateOrInsert(
            ['name' => 'Default Astrologer'],
            [
                'display_name' => 'Default Astrologer',
                'timezone' => 'Asia/Kolkata',
                'is_active' => true,
                'sort_order' => 1,
                'metadata' => json_encode(['created_for' => 'appointment_testing']),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        if (Schema::hasTable('astrologer_appointments') && ! Schema::hasColumn('astrologer_appointments', 'astrologer_id')) {
            Schema::table('astrologer_appointments', function (Blueprint $table): void {
                $table->foreignId('astrologer_id')
                    ->nullable()
                    ->after('prediction_request_id')
                    ->constrained('astrologers')
                    ->nullOnDelete();
                $table->index(['astrologer_id', 'confirmed_start_at'], 'astro_appts_astrologer_time_index');
            });
        }

        if (Schema::hasTable('astrologer_appointment_slot_blocks') && ! Schema::hasColumn('astrologer_appointment_slot_blocks', 'astrologer_id')) {
            Schema::table('astrologer_appointment_slot_blocks', function (Blueprint $table): void {
                $table->foreignId('astrologer_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('astrologers')
                    ->nullOnDelete();
                $table->index(['astrologer_id', 'start_at', 'end_at'], 'astro_slot_blocks_astrologer_time_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('astrologer_appointment_slot_blocks') && Schema::hasColumn('astrologer_appointment_slot_blocks', 'astrologer_id')) {
            Schema::table('astrologer_appointment_slot_blocks', function (Blueprint $table): void {
                $table->dropIndex('astro_slot_blocks_astrologer_time_index');
                $table->dropConstrainedForeignId('astrologer_id');
            });
        }

        if (Schema::hasTable('astrologer_appointments') && Schema::hasColumn('astrologer_appointments', 'astrologer_id')) {
            Schema::table('astrologer_appointments', function (Blueprint $table): void {
                $table->dropIndex('astro_appts_astrologer_time_index');
                $table->dropConstrainedForeignId('astrologer_id');
            });
        }

        Schema::dropIfExists('astrologers');
    }
};
