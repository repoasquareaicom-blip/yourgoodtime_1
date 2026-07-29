<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prediction_products', function (Blueprint $table): void {
            if (Schema::hasColumn('prediction_products', 'access_years')) {
                $table->dropColumn('access_years');
            }
        });
    }

    public function down(): void
    {
        Schema::table('prediction_products', function (Blueprint $table): void {
            if (! Schema::hasColumn('prediction_products', 'access_years')) {
                $table->unsignedSmallInteger('access_years')->default(5)->after('tax_rate');
            }
        });
    }
};
