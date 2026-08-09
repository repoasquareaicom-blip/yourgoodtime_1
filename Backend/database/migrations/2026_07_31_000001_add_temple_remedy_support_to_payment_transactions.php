<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table): void {
            if (! Schema::hasColumn('payment_transactions', 'temple_remedy_request_id')) {
                $table->foreignId('temple_remedy_request_id')
                    ->nullable()
                    ->after('prediction_request_id')
                    ->constrained('temple_remedy_requests')
                    ->nullOnDelete();
            }

            if (Schema::hasColumn('payment_transactions', 'prediction_request_id')) {
                $table->foreignId('prediction_request_id')
                    ->nullable()
                    ->change();
            }

            if (Schema::hasColumn('payment_transactions', 'product_id')) {
                $table->foreignId('product_id')
                    ->nullable()
                    ->change();
            }
        });
    }

    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table): void {
            if (Schema::hasColumn('payment_transactions', 'temple_remedy_request_id')) {
                $table->dropConstrainedForeignId('temple_remedy_request_id');
            }

            if (Schema::hasColumn('payment_transactions', 'prediction_request_id')) {
                $table->foreignId('prediction_request_id')
                    ->nullable(false)
                    ->change();
            }

            if (Schema::hasColumn('payment_transactions', 'product_id')) {
                $table->foreignId('product_id')
                    ->nullable(false)
                    ->change();
            }
        });
    }
};
