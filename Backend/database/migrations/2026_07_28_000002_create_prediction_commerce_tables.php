<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('prediction_feedback')) {
        Schema::create('prediction_feedback', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('prediction_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('prediction_result_id')->constrained()->cascadeOnDelete();
            $table->string('prediction_type', 24);
            $table->unsignedTinyInteger('rating');
            $table->string('accuracy_band', 32);
            $table->text('comments')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->unique(['prediction_result_id', 'prediction_type'], 'prediction_feedback_result_type_unique');
        });
        }

        if (! Schema::hasTable('prediction_products')) {
        Schema::create('prediction_products', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 80)->unique();
            $table->string('name');
            $table->string('prediction_type', 24);
            $table->unsignedInteger('price_inclusive_tax_minor');
            $table->string('currency', 3)->default('INR');
            $table->boolean('tax_inclusive')->default(true);
            $table->decimal('tax_rate', 5, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        }

        if (! Schema::hasTable('payment_transactions')) {
        Schema::create('payment_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('prediction_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->constrained('prediction_products')->restrictOnDelete();
            $table->string('provider', 32);
            $table->string('currency', 3);
            $table->unsignedInteger('amount_minor');
            $table->boolean('tax_inclusive')->default(true);
            $table->string('status', 32);
            $table->string('provider_order_id')->nullable()->unique();
            $table->string('provider_payment_id')->nullable()->unique();
            $table->string('provider_signature')->nullable();
            $table->string('failure_code')->nullable();
            $table->text('failure_description')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('prediction_request_id');
            $table->index('status');
        });
        }

        if (! Schema::hasTable('payment_events')) {
        Schema::create('payment_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 32);
            $table->string('provider_event_id')->nullable();
            $table->string('event_hash', 64);
            $table->string('event_type', 80);
            $table->json('payload');
            $table->boolean('signature_verified')->default(false);
            $table->timestamp('processed_at')->nullable();
            $table->string('processing_status', 32)->default('PENDING');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'event_hash']);
            $table->index(['provider', 'provider_event_id']);
        });
        }

        if (! Schema::hasTable('prediction_entitlements')) {
        Schema::create('prediction_entitlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('prediction_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->constrained('prediction_products')->restrictOnDelete();
            $table->foreignId('payment_transaction_id')->constrained()->cascadeOnDelete();
            $table->string('prediction_type', 24);
            $table->unsignedSmallInteger('access_start_year');
            $table->unsignedSmallInteger('access_end_year');
            $table->timestamp('starts_at');
            $table->timestamp('expires_at');
            $table->string('status', 32);
            $table->foreignId('generated_prediction_result_id')->nullable()->constrained('prediction_results')->nullOnDelete();
            $table->timestamps();

            $table->unique(['payment_transaction_id', 'prediction_type'], 'prediction_entitlements_payment_type_unique');
            $table->index(['prediction_request_id', 'prediction_type', 'status'], 'pred_ent_req_type_status_idx');
            $table->index('expires_at', 'pred_ent_expires_idx');
        });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('prediction_entitlements');
        Schema::dropIfExists('payment_events');
        Schema::dropIfExists('payment_transactions');
        Schema::dropIfExists('prediction_products');
        Schema::dropIfExists('prediction_feedback');
    }
};
