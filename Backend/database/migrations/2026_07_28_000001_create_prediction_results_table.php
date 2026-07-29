<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prediction_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('prediction_request_id')->constrained()->cascadeOnDelete();
            $table->string('prediction_type', 24);
            $table->string('identity_video_id')->nullable();
            $table->json('result_payload');
            $table->json('summary_payload')->nullable();
            $table->json('warnings_payload')->nullable();
            $table->string('status', 32)->default('completed');
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->unique(['prediction_request_id', 'prediction_type'], 'prediction_results_request_type_unique');
            $table->index(['prediction_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prediction_results');
    }
};
