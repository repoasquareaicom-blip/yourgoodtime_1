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
                'price_inclusive_tax_minor' => 60000,
                'currency' => 'INR',
                'tax_inclusive' => true,
                'tax_rate' => null,
                'is_active' => true,
                'metadata' => [],
            ]
        );
    }
}
