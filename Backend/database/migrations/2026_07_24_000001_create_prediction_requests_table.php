<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prediction_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_uuid')->unique();
            $table->string('prediction_type')->default('past');
            $table->string('status')->default('pending');
            $table->string('full_name');
            $table->date('date_of_birth');
            $table->time('time_of_birth');
            $table->string('timezone')->default('Asia/Kolkata');
            $table->string('place_of_birth');
            $table->string('country')->nullable();
            $table->string('country_code', 2)->nullable();
            $table->decimal('latitude', 10, 6);
            $table->decimal('longitude', 10, 6);
            $table->string('marriage_status')->nullable();
            $table->string('email')->nullable();
            $table->json('birth_payload');
            $table->json('raw_astrology_response')->nullable();
            $table->json('normalized_astrology_data')->nullable();
            $table->unsignedSmallInteger('api_call_count')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->index(['prediction_type', 'status']);
            $table->index('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prediction_requests');
    }
};
