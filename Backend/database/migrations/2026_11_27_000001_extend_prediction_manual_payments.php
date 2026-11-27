<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $changeColumns = fn () => Schema::table('future_video_manual_payments', function (Blueprint $table): void {
            $table->unsignedBigInteger('product_id')->nullable()->change();
            $table->string('payment_proof_path')->nullable()->change();
            $table->string('transaction_reference', 120)->nullable();
            $table->unique('transaction_reference', 'prediction_manual_transaction_reference_unique');
        });

        if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            Schema::withoutForeignKeyConstraints($changeColumns);
        } else {
            $changeColumns();
        }
    }
};
