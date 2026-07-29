<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PredictionProduct extends Model
{
    protected $fillable = [
        'code',
        'name',
        'description',
        'prediction_type',
        'price_inclusive_tax_minor',
        'currency',
        'tax_inclusive',
        'tax_rate',
        'is_active',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'tax_inclusive' => 'boolean',
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }
}
