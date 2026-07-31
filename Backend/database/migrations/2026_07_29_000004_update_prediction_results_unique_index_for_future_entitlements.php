<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prediction_results', function (Blueprint $table): void {
            $table->index('prediction_request_id', 'prediction_results_request_id_lookup');
            $table->dropUnique('prediction_results_request_type_unique');
            $table->unique(
                ['prediction_request_id', 'prediction_type', 'prediction_entitlement_id'],
                'prediction_results_request_type_entitlement_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('prediction_results', function (Blueprint $table): void {
            $table->dropUnique('prediction_results_request_type_entitlement_unique');
            $table->unique(['prediction_request_id', 'prediction_type'], 'prediction_results_request_type_unique');
            $table->dropIndex('prediction_results_request_id_lookup');
        });
    }
};
