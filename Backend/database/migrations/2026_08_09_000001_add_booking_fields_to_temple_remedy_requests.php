<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temple_remedy_requests', function (Blueprint $table): void {
            if (! Schema::hasColumn('temple_remedy_requests', 'remedy_option_code')) {
                $table->string('remedy_option_code', 80)->nullable()->after('prediction_request_id');
            }

            if (! Schema::hasColumn('temple_remedy_requests', 'remedy_option_title')) {
                $table->string('remedy_option_title', 160)->nullable()->after('remedy_option_code');
            }

            if (! Schema::hasColumn('temple_remedy_requests', 'contact_number')) {
                $table->string('contact_number', 32)->nullable()->after('problem_text');
            }

            if (! Schema::hasColumn('temple_remedy_requests', 'alternate_contact_number')) {
                $table->string('alternate_contact_number', 32)->nullable()->after('contact_number');
            }

            if (! Schema::hasColumn('temple_remedy_requests', 'preferred_language')) {
                $table->string('preferred_language', 16)->nullable()->after('alternate_contact_number');
            }

            if (! Schema::hasColumn('temple_remedy_requests', 'payment_method_code')) {
                $table->string('payment_method_code', 40)->nullable()->after('preferred_language');
            }

            if (! Schema::hasColumn('temple_remedy_requests', 'manual_payment')) {
                $table->json('manual_payment')->nullable()->after('payment_method_code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('temple_remedy_requests', function (Blueprint $table): void {
            foreach ([
                'manual_payment',
                'payment_method_code',
                'preferred_language',
                'alternate_contact_number',
                'contact_number',
                'remedy_option_title',
                'remedy_option_code',
            ] as $column) {
                if (Schema::hasColumn('temple_remedy_requests', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
