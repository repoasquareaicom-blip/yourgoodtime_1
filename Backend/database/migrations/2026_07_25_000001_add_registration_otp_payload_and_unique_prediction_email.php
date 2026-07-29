<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('otp_records', 'pending_payload')) {
            Schema::table('otp_records', function (Blueprint $table): void {
                $table->json('pending_payload')->nullable()->after('used_at');
            });
        }

        $duplicateEmails = DB::table('prediction_requests')
            ->selectRaw('LOWER(TRIM(email)) as normalized_email')
            ->whereNotNull('email')
            ->where('email', '<>', '')
            ->groupBy('normalized_email')
            ->havingRaw('COUNT(*) > 1')
            ->limit(1)
            ->exists();

        if ($duplicateEmails) {
            throw new RuntimeException('Cannot add unique prediction_requests.email index while duplicate emails exist.');
        }

        if (! $this->hasUniqueEmailIndex()) {
            Schema::table('prediction_requests', function (Blueprint $table): void {
                $table->unique('email', 'prediction_requests_email_unique');
            });
        }
    }

    public function down(): void
    {
        if ($this->hasUniqueEmailIndex('prediction_requests_email_unique')) {
            Schema::table('prediction_requests', function (Blueprint $table): void {
                $table->dropUnique('prediction_requests_email_unique');
            });
        }

        if (Schema::hasColumn('otp_records', 'pending_payload')) {
            Schema::table('otp_records', function (Blueprint $table): void {
                $table->dropColumn('pending_payload');
            });
        }
    }

    private function hasUniqueEmailIndex(?string $name = null): bool
    {
        foreach (Schema::getIndexes('prediction_requests') as $index) {
            $indexName = $index['name'] ?? null;
            $columns = $index['columns'] ?? [];

            if ($name !== null && $indexName === $name) {
                return true;
            }

            if (($index['unique'] ?? false) && $columns === ['email']) {
                return true;
            }
        }

        return false;
    }
};
