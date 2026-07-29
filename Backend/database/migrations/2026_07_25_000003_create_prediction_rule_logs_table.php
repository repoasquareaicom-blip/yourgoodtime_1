<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prediction_rule_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('prediction_request_id')->constrained('prediction_requests')->cascadeOnDelete();
            $table->string('prediction_type');
            $table->unsignedSmallInteger('target_year')->nullable();
            $table->date('target_date')->nullable();
            $table->unsignedSmallInteger('age')->nullable();
            $table->string('rule_code')->nullable();
            $table->string('rule_name')->nullable();
            $table->string('status');
            $table->string('error_code')->nullable();
            $table->text('message')->nullable();
            $table->json('context_json')->nullable();
            $table->timestamps();

            $table->index(['prediction_request_id', 'prediction_type']);
            $table->index(['rule_code', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prediction_rule_logs');
    }
};
