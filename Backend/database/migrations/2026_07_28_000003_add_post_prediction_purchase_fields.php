<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prediction_products', function (Blueprint $table): void {
            if (! Schema::hasColumn('prediction_products', 'description')) {
                $table->text('description')->nullable()->after('name');
            }
        });

        Schema::table('prediction_entitlements', function (Blueprint $table): void {
            if (! Schema::hasColumn('prediction_entitlements', 'purchased_years')) {
                $table->unsignedSmallInteger('purchased_years')->default(1)->after('prediction_type');
            }

            if (! Schema::hasColumn('prediction_entitlements', 'purchased_at')) {
                $table->timestamp('purchased_at')->nullable()->after('expires_at');
            }
        });

        Schema::table('prediction_results', function (Blueprint $table): void {
            if (! Schema::hasColumn('prediction_results', 'user_id')) {
                $table->foreignId('user_id')->nullable()->after('prediction_request_id')->constrained()->nullOnDelete();
            }

            if (! Schema::hasColumn('prediction_results', 'prediction_entitlement_id')) {
                $table->foreignId('prediction_entitlement_id')->nullable()->after('user_id')->constrained('prediction_entitlements')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('prediction_results', function (Blueprint $table): void {
            if (Schema::hasColumn('prediction_results', 'prediction_entitlement_id')) {
                $table->dropConstrainedForeignId('prediction_entitlement_id');
            }

            if (Schema::hasColumn('prediction_results', 'user_id')) {
                $table->dropConstrainedForeignId('user_id');
            }
        });

        Schema::table('prediction_entitlements', function (Blueprint $table): void {
            if (Schema::hasColumn('prediction_entitlements', 'purchased_at')) {
                $table->dropColumn('purchased_at');
            }

            if (Schema::hasColumn('prediction_entitlements', 'purchased_years')) {
                $table->dropColumn('purchased_years');
            }
        });

        Schema::table('prediction_products', function (Blueprint $table): void {
            if (Schema::hasColumn('prediction_products', 'description')) {
                $table->dropColumn('description');
            }
        });
    }
};
