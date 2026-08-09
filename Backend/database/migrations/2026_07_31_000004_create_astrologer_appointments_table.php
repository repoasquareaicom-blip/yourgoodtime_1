<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('astrologer_appointments')) {
            Schema::create('astrologer_appointments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('prediction_request_id')->nullable()->constrained()->nullOnDelete();
                $table->string('appointment_number', 40)->unique();
                $table->string('status', 32)->default('pending');
                $table->text('notes')->nullable();
                $table->date('preferred_date');
                $table->time('preferred_time');
                $table->string('preferred_timezone', 64)->default('Asia/Kolkata');
                $table->timestamp('confirmed_start_at')->nullable();
                $table->timestamp('confirmed_end_at')->nullable();
                $table->string('confirmed_timezone', 64)->nullable();
                $table->unsignedSmallInteger('duration_minutes');
                $table->unsignedTinyInteger('maximum_questions')->default(3);
                $table->unsignedInteger('amount_minor')->default(0);
                $table->string('currency', 3)->default('INR');
                $table->boolean('tax_inclusive')->default(true);
                $table->string('payment_stage', 32)->default('after_slot_confirmation');
                $table->string('payment_status', 32)->default('NOT_REQUIRED');
                $table->foreignId('payment_transaction_id')->nullable()->constrained()->nullOnDelete();
                $table->timestamp('paid_at')->nullable();
                $table->string('meeting_provider', 32)->nullable();
                $table->text('meeting_link')->nullable();
                $table->string('calendar_event_id')->nullable();
                $table->text('admin_note')->nullable();
                $table->foreignId('confirmed_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
                $table->timestamp('confirmed_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->timestamp('request_acknowledgement_sent_at')->nullable();
                $table->timestamp('slot_email_sent_at')->nullable();
                $table->timestamp('payment_email_sent_at')->nullable();
                $table->timestamp('meeting_email_sent_at')->nullable();
                $table->timestamp('reminder_24h_sent_at')->nullable();
                $table->timestamp('reminder_1h_sent_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'status']);
                $table->index(['status', 'preferred_date']);
                $table->index(['confirmed_start_at', 'confirmed_end_at']);
                $table->index('payment_status');
            });
        }

        if (! Schema::hasTable('astrologer_appointment_questions')) {
            Schema::create('astrologer_appointment_questions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('appointment_id')->constrained('astrologer_appointments')->cascadeOnDelete();
                $table->unsignedTinyInteger('sequence');
                $table->string('question', 500);
                $table->timestamps();

                $table->unique(['appointment_id', 'sequence'], 'astro_appt_questions_sequence_unique');
            });
        }

        if (! Schema::hasTable('astrologer_appointment_activities')) {
            Schema::create('astrologer_appointment_activities', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('appointment_id')->constrained('astrologer_appointments')->cascadeOnDelete();
                $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('actor_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
                $table->string('event_type', 80);
                $table->string('title');
                $table->json('payload')->nullable();
                $table->timestamps();

                $table->index(['appointment_id', 'event_type']);
            });
        }

        if (! Schema::hasTable('astrologer_appointment_slot_blocks')) {
            Schema::create('astrologer_appointment_slot_blocks', function (Blueprint $table): void {
                $table->id();
                $table->timestamp('start_at');
                $table->timestamp('end_at');
                $table->string('timezone', 64)->default('Asia/Kolkata');
                $table->string('reason')->nullable();
                $table->foreignId('created_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
                $table->timestamps();

                $table->index(['start_at', 'end_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('astrologer_appointment_slot_blocks');
        Schema::dropIfExists('astrologer_appointment_activities');
        Schema::dropIfExists('astrologer_appointment_questions');
        Schema::dropIfExists('astrologer_appointments');
    }
};
