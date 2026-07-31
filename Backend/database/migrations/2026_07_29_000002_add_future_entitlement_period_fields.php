<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prediction_entitlements', function (Blueprint $table): void {
            if (! Schema::hasColumn('prediction_entitlements', 'start_age')) {
                $table->unsignedSmallInteger('start_age')->nullable()->after('access_end_year');
            }

            if (! Schema::hasColumn('prediction_entitlements', 'end_age')) {
                $table->unsignedSmallInteger('end_age')->nullable()->after('start_age');
            }

            if (! Schema::hasColumn('prediction_entitlements', 'allocated_year_count')) {
                $table->unsignedSmallInteger('allocated_year_count')->nullable()->after('end_age');
            }

            $table->unique(
                ['user_id', 'prediction_type', 'access_start_year', 'access_end_year'],
                'pred_ent_user_type_period_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('prediction_entitlements', function (Blueprint $table): void {
            $table->dropUnique('pred_ent_user_type_period_unique');

            if (Schema::hasColumn('prediction_entitlements', 'allocated_year_count')) {
                $table->dropColumn('allocated_year_count');
            }

            if (Schema::hasColumn('prediction_entitlements', 'end_age')) {
                $table->dropColumn('end_age');
            }

            if (Schema::hasColumn('prediction_entitlements', 'start_age')) {
                $table->dropColumn('start_age');
            }
        });
    }
};
