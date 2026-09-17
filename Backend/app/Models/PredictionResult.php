<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PredictionResult extends Model
{
    protected $fillable = [
        'prediction_request_id',
        'user_id',
        'prediction_entitlement_id',
        'prediction_type',
        'identity_video_id',
        'result_payload',
        'summary_payload',
        'warnings_payload',
        'status',
        'regeneration_version',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'result_payload' => 'array',
            'summary_payload' => 'array',
            'warnings_payload' => 'array',
            'regeneration_version' => 'integer',
            'generated_at' => 'datetime',
        ];
    }

    public function predictionRequest(): BelongsTo
    {
        return $this->belongsTo(PredictionRequest::class);
    }
}
