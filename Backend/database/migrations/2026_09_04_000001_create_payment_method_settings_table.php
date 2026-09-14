<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SERVICE_TYPES = [
        'FUTURE_VIDEO',
        'TALK_TO_ASTROLOGER',
        'TEMPLE_REMEDY',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('payment_method_settings')) {
            Schema::create('payment_method_settings', function (Blueprint $table): void {
                $table->id();
                $table->string('service_type', 60)->unique();
                $table->boolean('payment_gateway_enabled')->default(true);
                $table->boolean('gpay_qr_enabled')->default(false);
                $table->string('qr_image_path')->nullable();
                $table->foreignId('updated_by')->nullable()->constrained('admin_users')->nullOnDelete();
                $table->timestamps();

                $table->index(['service_type', 'payment_gateway_enabled', 'gpay_qr_enabled'], 'payment_method_settings_service_flags_idx');
            });
        }

        $now = now();
        foreach (self::SERVICE_TYPES as $serviceType) {
            DB::table('payment_method_settings')->updateOrInsert(
                ['service_type' => $serviceType],
                [
                    'payment_gateway_enabled' => true,
                    'gpay_qr_enabled' => false,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_method_settings');
    }
};
