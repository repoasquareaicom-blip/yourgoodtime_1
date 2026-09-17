<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PredictionRegenerationSetting extends Model
{
    protected $fillable = [
        'prediction_type',
        'regeneration_version',
        'is_enabled',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'regeneration_version' => 'integer',
            'is_enabled' => 'boolean',
        ];
    }
}
