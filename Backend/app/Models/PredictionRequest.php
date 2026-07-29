<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PredictionRequest extends Model
{
    protected $fillable = [
        'request_uuid',
        'chart_fingerprint',
        'prediction_type',
        'status',
        'full_name',
        'gender',
        'date_of_birth',
        'time_of_birth',
        'timezone',
        'place_of_birth',
        'country',
        'country_code',
        'latitude',
        'longitude',
        'marriage_status',
        'email',
        'birth_payload',
        'raw_astrology_response',
        'normalized_astrology_data',
        'api_call_count',
        'cache_hit',
        'reused_from_request_id',
        'error_message',
        'submitted_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'latitude' => 'decimal:6',
            'longitude' => 'decimal:6',
            'birth_payload' => 'array',
            'raw_astrology_response' => 'array',
            'normalized_astrology_data' => 'array',
            'cache_hit' => 'boolean',
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function results(): HasMany
    {
        return $this->hasMany(PredictionResult::class);
    }
}
