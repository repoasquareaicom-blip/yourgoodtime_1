<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'country_code')) {
                $table->string('country_code', 2)->nullable()->after('email');
            }

            if (! Schema::hasColumn('users', 'phone_country_calling_code')) {
                $table->string('phone_country_calling_code', 8)->nullable()->after('country_code');
            }

            if (! Schema::hasColumn('users', 'phone_number')) {
                $table->string('phone_number', 32)->nullable()->after('phone_country_calling_code');
            }

            if (! Schema::hasColumn('users', 'phone_e164')) {
                $table->string('phone_e164', 32)->nullable()->after('phone_number');
            }

            if (! Schema::hasColumn('users', 'phone_source')) {
                $table->string('phone_source', 32)->nullable()->after('phone_e164');
            }

            if (! Schema::hasColumn('users', 'phone_verified_at')) {
                $table->timestamp('phone_verified_at')->nullable()->after('phone_source');
            }
        });

        Schema::table('payment_transactions', function (Blueprint $table): void {
            if (! Schema::hasColumn('payment_transactions', 'payment_contact')) {
                $table->string('payment_contact', 64)->nullable()->after('provider_signature');
            }

            if (! Schema::hasColumn('payment_transactions', 'payment_email')) {
                $table->string('payment_email')->nullable()->after('payment_contact');
            }

            if (! Schema::hasColumn('payment_transactions', 'captured_at')) {
                $table->timestamp('captured_at')->nullable()->after('verified_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table): void {
            $columns = array_values(array_filter([
                Schema::hasColumn('payment_transactions', 'captured_at') ? 'captured_at' : null,
                Schema::hasColumn('payment_transactions', 'payment_email') ? 'payment_email' : null,
                Schema::hasColumn('payment_transactions', 'payment_contact') ? 'payment_contact' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });

        Schema::table('users', function (Blueprint $table): void {
            $columns = array_values(array_filter([
                Schema::hasColumn('users', 'phone_verified_at') ? 'phone_verified_at' : null,
                Schema::hasColumn('users', 'phone_source') ? 'phone_source' : null,
                Schema::hasColumn('users', 'phone_e164') ? 'phone_e164' : null,
                Schema::hasColumn('users', 'phone_number') ? 'phone_number' : null,
                Schema::hasColumn('users', 'phone_country_calling_code') ? 'phone_country_calling_code' : null,
                Schema::hasColumn('users', 'country_code') ? 'country_code' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
