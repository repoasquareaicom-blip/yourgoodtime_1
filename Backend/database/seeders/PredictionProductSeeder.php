<?php

namespace Database\Seeders;

use App\Models\PredictionProduct;
use Illuminate\Database\Seeder;

class PredictionProductSeeder extends Seeder
{
    public function run(): void
    {
        PredictionProduct::query()->updateOrCreate(
            ['code' => 'FUTURE_PREDICTION'],
            [
                'name' => 'Future Prediction',
                'description' => 'Unlock your personalised future prediction video journey.',
                'prediction_type' => 'future',
                'price_inclusive_tax_minor' => 600000,
                'currency' => 'INR',
                'tax_inclusive' => true,
                'tax_rate' => null,
                'is_active' => true,
                'metadata' => [],
            ]
        );

        PredictionProduct::query()->updateOrCreate(
            ['code' => 'ASTROLOGER_CONSULTATION'],
            [
                'name' => 'Talk to Astrologer',
                'description' => 'Direct online consultation with the astrologer for personalised guidance.',
                'prediction_type' => 'appointment',
                'price_inclusive_tax_minor' => 60000,
                'currency' => 'INR',
                'tax_inclusive' => true,
                'tax_rate' => null,
                'is_active' => true,
                'metadata' => [
                    'maximum_questions' => 3,
                ],
            ]
        );

        PredictionProduct::query()->updateOrCreate(
            ['code' => 'TEMPLE_REMEDY'],
            [
                'name' => 'Temple Remedy',
                'description' => 'Temple remedy guidance request with admin review and payment confirmation.',
                'prediction_type' => 'temple_remedy',
                'price_inclusive_tax_minor' => 100000,
                'currency' => 'INR',
                'tax_inclusive' => true,
                'tax_rate' => null,
                'is_active' => true,
                'metadata' => [
                    'maximum_options' => 10,
                ],
            ]
        );
    }
}
