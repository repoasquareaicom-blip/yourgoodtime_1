<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temple_remedy_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('problem_text');
            $table->string('status', 32)->default('RECEIVED');
            $table->boolean('payment_enabled')->default(false);
            $table->unsignedInteger('amount_minor')->nullable();
            $table->string('currency', 3)->default('INR');
            $table->string('payment_status', 32)->default('NOT_REQUIRED');
            $table->timestamp('payment_enabled_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('remedy_type', 24)->nullable();
            $table->string('remedy_title')->nullable();
            $table->string('remedy_file_path')->nullable();
            $table->string('remedy_url')->nullable();
            $table->timestamp('remedy_uploaded_at')->nullable();
            $table->text('admin_note')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->index(['user_id', 'submitted_at']);
            $table->index(['status', 'payment_enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temple_remedy_requests');
    }
};
