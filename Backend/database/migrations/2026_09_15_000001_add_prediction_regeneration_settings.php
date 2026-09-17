<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prediction_regeneration_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('prediction_type', 20)->unique();
            $table->unsignedInteger('regeneration_version')->default(0);
            $table->boolean('is_enabled')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('prediction_results', function (Blueprint $table): void {
            $table->unsignedInteger('regeneration_version')->default(0)->after('status');
        });

        DB::table('prediction_regeneration_settings')->insert([
            [
                'prediction_type' => 'past',
                'regeneration_version' => 0,
                'is_enabled' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'prediction_type' => 'future',
                'regeneration_version' => 0,
                'is_enabled' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::table('prediction_results', function (Blueprint $table): void {
            $table->dropColumn('regeneration_version');
        });

        Schema::dropIfExists('prediction_regeneration_settings');
    }
};
