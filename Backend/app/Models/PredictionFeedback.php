<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PredictionFeedback extends Model
{
    protected $table = 'prediction_feedback';

    protected $fillable = [
        'prediction_request_id',
        'prediction_result_id',
        'prediction_type',
        'rating',
        'accuracy_band',
        'comments',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
        ];
    }
}
