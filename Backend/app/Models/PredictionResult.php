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

    public function hasReadingVideos(): bool
    {
        return collect(data_get($this->result_payload, 'prediction_videos', []))
            ->contains(fn ($group): bool => filled(data_get($group, 'video_id'))
                || collect((array) data_get($group, 'video_ids', []))->contains(fn ($id): bool => is_scalar($id) && filled($id)));
    }
}
