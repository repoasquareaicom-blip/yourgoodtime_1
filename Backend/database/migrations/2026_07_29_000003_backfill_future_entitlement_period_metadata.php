<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('prediction_entitlements') || ! Schema::hasColumn('prediction_entitlements', 'allocated_year_count')) {
            return;
        }

        DB::table('prediction_entitlements')
            ->where('prediction_type', 'future')
            ->whereNull('allocated_year_count')
            ->orderBy('id')
            ->chunkById(100, function ($entitlements): void {
                foreach ($entitlements as $entitlement) {
                    $updates = [
                        'allocated_year_count' => max(1, ((int) $entitlement->access_end_year) - ((int) $entitlement->access_start_year) + 1),
                    ];

                    $request = DB::table('prediction_requests')->where('id', $entitlement->prediction_request_id)->first();
                    if ($request?->date_of_birth) {
                        $birthDate = CarbonImmutable::parse($request->date_of_birth);
                        $currentYear = ((int) $entitlement->access_start_year) - 1;
                        $startDate = CarbonImmutable::create($currentYear, 7, 1);
                        $startAge = (int) $birthDate->diffInYears($startDate) + 1;

                        if ($entitlement->start_age === null) {
                            $updates['start_age'] = $startAge;
                        }

                        if ($entitlement->end_age === null) {
                            $updates['end_age'] = $startAge + $updates['allocated_year_count'] - 1;
                        }
                    }

                    DB::table('prediction_entitlements')->where('id', $entitlement->id)->update($updates);
                }
            });
    }

    public function down(): void
    {
        // Backfilled metadata is intentionally retained.
    }
};
