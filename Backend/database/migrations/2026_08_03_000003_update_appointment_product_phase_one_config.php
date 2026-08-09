<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $product = DB::table('prediction_products')
            ->where('code', 'ASTROLOGER_CONSULTATION')
            ->first();

        if (! $product) {
            return;
        }

        $metadata = json_decode((string) ($product?->metadata ?? '{}'), true);
        $maximumQuestions = is_array($metadata) ? (int) ($metadata['maximum_questions'] ?? 3) : 3;

        DB::table('prediction_products')
            ->where('code', 'ASTROLOGER_CONSULTATION')
            ->update([
                'price_inclusive_tax_minor' => 600000,
                'metadata' => json_encode([
                    'maximum_questions' => max(1, $maximumQuestions ?: 3),
                ]),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        $product = DB::table('prediction_products')
            ->where('code', 'ASTROLOGER_CONSULTATION')
            ->first();

        if (! $product) {
            return;
        }

        $metadata = json_decode((string) ($product->metadata ?? '{}'), true);
        $maximumQuestions = is_array($metadata) ? (int) ($metadata['maximum_questions'] ?? 3) : 3;

        DB::table('prediction_products')
            ->where('code', 'ASTROLOGER_CONSULTATION')
            ->update([
                'price_inclusive_tax_minor' => 60000,
                'metadata' => json_encode([
                    'maximum_questions' => max(1, $maximumQuestions ?: 3),
                ]),
                'updated_at' => now(),
            ]);
    }
};
