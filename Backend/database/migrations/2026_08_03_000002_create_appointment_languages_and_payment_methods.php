<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('appointment_languages')) {
            Schema::create('appointment_languages', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 80);
                $table->string('code', 16)->unique();
                $table->boolean('allow_appointment')->default(false);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['allow_appointment', 'sort_order']);
            });
        }

        if (! Schema::hasTable('appointment_payment_methods')) {
            Schema::create('appointment_payment_methods', function (Blueprint $table): void {
                $table->id();
                $table->string('code', 40)->unique();
                $table->string('name', 120);
                $table->boolean('is_active')->default(true);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->text('instructions')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['is_active', 'sort_order']);
            });
        }

        $now = now();
        foreach ([
            ['Tamil', 'ta', true, 1],
            ['English', 'en', true, 2],
            ['Hindi', 'hi', false, 3],
        ] as [$name, $code, $allowAppointment, $sortOrder]) {
            DB::table('appointment_languages')->updateOrInsert(
                ['code' => $code],
                [
                    'name' => $name,
                    'allow_appointment' => $allowAppointment,
                    'sort_order' => $sortOrder,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }

        foreach ([
            ['payment_gateway', 'Payment Gateway', true, 1, 'Pay securely through Razorpay.'],
            ['qr_online', 'Pay through QR / Online', true, 2, 'Pay through QR, GPay, or online transfer, then upload the payment reference/proof.'],
        ] as [$code, $name, $isActive, $sortOrder, $instructions]) {
            DB::table('appointment_payment_methods')->updateOrInsert(
                ['code' => $code],
                [
                    'name' => $name,
                    'is_active' => $isActive,
                    'sort_order' => $sortOrder,
                    'instructions' => $instructions,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }

        if (Schema::hasTable('astrologer_appointments')) {
            Schema::table('astrologer_appointments', function (Blueprint $table): void {
                if (! Schema::hasColumn('astrologer_appointments', 'appointment_language_id')) {
                    $table->foreignId('appointment_language_id')
                        ->nullable()
                        ->after('prediction_request_id')
                        ->constrained('appointment_languages')
                        ->nullOnDelete();
                }

                if (! Schema::hasColumn('astrologer_appointments', 'payment_method_code')) {
                    $table->string('payment_method_code', 40)
                        ->nullable()
                        ->after('payment_stage');
                    $table->index('payment_method_code');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('astrologer_appointments')) {
            Schema::table('astrologer_appointments', function (Blueprint $table): void {
                if (Schema::hasColumn('astrologer_appointments', 'payment_method_code')) {
                    $table->dropIndex(['payment_method_code']);
                    $table->dropColumn('payment_method_code');
                }

                if (Schema::hasColumn('astrologer_appointments', 'appointment_language_id')) {
                    $table->dropConstrainedForeignId('appointment_language_id');
                }
            });
        }

        Schema::dropIfExists('appointment_payment_methods');
        Schema::dropIfExists('appointment_languages');
    }
};
