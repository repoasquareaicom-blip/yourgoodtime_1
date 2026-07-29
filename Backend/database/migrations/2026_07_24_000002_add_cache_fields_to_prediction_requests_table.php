<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prediction_requests', function (Blueprint $table): void {
            $table->string('chart_fingerprint', 64)->nullable()->after('request_uuid');
            $table->string('gender')->nullable()->after('full_name');
            $table->boolean('cache_hit')->default(false)->after('api_call_count');
            $table->foreignId('reused_from_request_id')
                ->nullable()
                ->after('cache_hit')
                ->constrained('prediction_requests')
                ->nullOnDelete();
            $table->timestamp('completed_at')->nullable()->after('submitted_at');

            $table->index('chart_fingerprint');
            $table->index(['prediction_type', 'status', 'chart_fingerprint'], 'prediction_requests_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::table('prediction_requests', function (Blueprint $table): void {
            $table->dropForeign(['reused_from_request_id']);
            $table->dropIndex('prediction_requests_lookup_idx');
            $table->dropIndex(['chart_fingerprint']);
            $table->dropColumn([
                'chart_fingerprint',
                'gender',
                'cache_hit',
                'reused_from_request_id',
                'completed_at',
            ]);
        });
    }
};
