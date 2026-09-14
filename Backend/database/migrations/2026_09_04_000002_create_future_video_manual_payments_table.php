<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('future_video_manual_payments')) {
            Schema::create('future_video_manual_payments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('prediction_request_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('prediction_products')->restrictOnDelete();
                $table->string('service_type', 60)->default('FUTURE_VIDEO');
                $table->string('purchase_reference', 160)->unique();
                $table->unsignedInteger('amount_minor');
                $table->string('currency', 3)->default('INR');
                $table->string('payment_method', 32)->default('GPAY_QR');
                $table->string('payment_proof_path');
                $table->string('payment_proof_original_name')->nullable();
                $table->string('payment_proof_mime_type', 120)->nullable();
                $table->unsignedInteger('payment_proof_size')->nullable();
                $table->string('status', 32)->default('PENDING');
                $table->timestamp('submitted_at')->nullable();
                $table->timestamp('verified_at')->nullable();
                $table->foreignId('verified_by')->nullable()->constrained('admin_users')->nullOnDelete();
                $table->text('rejection_reason')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'prediction_request_id', 'status'], 'future_video_manual_user_request_status_idx');
                $table->index(['service_type', 'status'], 'future_video_manual_service_status_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('future_video_manual_payments');
    }
};
