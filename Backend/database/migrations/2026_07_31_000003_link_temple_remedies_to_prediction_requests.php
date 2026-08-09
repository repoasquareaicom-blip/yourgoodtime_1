<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temple_remedy_requests', function (Blueprint $table): void {
            if (! Schema::hasColumn('temple_remedy_requests', 'prediction_request_id')) {
                $table->foreignId('prediction_request_id')
                    ->nullable()
                    ->after('user_id')
                    ->constrained('prediction_requests')
                    ->nullOnDelete();
            }
        });

        DB::table('temple_remedy_requests')
            ->join('users', 'users.id', '=', 'temple_remedy_requests.user_id')
            ->whereNull('temple_remedy_requests.prediction_request_id')
            ->orderBy('temple_remedy_requests.id')
            ->select('temple_remedy_requests.id', 'users.email')
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    $predictionRequestId = DB::table('prediction_requests')
                        ->where('email', $row->email)
                        ->latest('submitted_at')
                        ->latest('id')
                        ->value('id');

                    if ($predictionRequestId) {
                        DB::table('temple_remedy_requests')
                            ->where('id', $row->id)
                            ->update(['prediction_request_id' => $predictionRequestId]);
                    }
                }
            }, 'temple_remedy_requests.id', 'id');
    }

    public function down(): void
    {
        Schema::table('temple_remedy_requests', function (Blueprint $table): void {
            if (Schema::hasColumn('temple_remedy_requests', 'prediction_request_id')) {
                $table->dropConstrainedForeignId('prediction_request_id');
            }
        });
    }
};
