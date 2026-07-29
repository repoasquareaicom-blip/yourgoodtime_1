<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('astrology_sessions')) {
            Schema::create('astrology_sessions', function (Blueprint $table): void {
                $table->id('session_id');
                $table->uuid('session_uuid')->unique();
                $table->string('session_type', 40)->default('birth_details');
                $table->timestamp('started_at')->nullable();
                $table->string('selected_place_name')->nullable();
                $table->string('selected_country')->nullable();
                $table->decimal('selected_latitude', 10, 6)->nullable();
                $table->decimal('selected_longitude', 10, 6)->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('astrology_api_logs')) {
            Schema::create('astrology_api_logs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('session_id')
                    ->nullable()
                    ->constrained('astrology_sessions', 'session_id')
                    ->nullOnDelete();
                $table->string('api_name', 80);
                $table->string('request_type', 80);
                $table->string('search_query')->nullable();
                $table->boolean('cache_hit')->default(false);
                $table->boolean('external_api_called')->default(false);
                $table->decimal('estimated_cost', 8, 2)->default(0);
                $table->unsignedSmallInteger('response_status')->nullable();
                $table->unsignedInteger('response_time_ms')->default(0);
                $table->unsignedSmallInteger('result_count')->default(0);
                $table->text('error_message')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index(['session_id', 'api_name']);
                $table->index('created_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('astrology_api_logs');
        Schema::dropIfExists('astrology_sessions');
    }
};
