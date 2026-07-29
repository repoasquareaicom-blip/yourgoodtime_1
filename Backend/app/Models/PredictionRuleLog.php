<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PredictionRuleLog extends Model
{
    protected $fillable = [
        'prediction_request_id',
        'prediction_type',
        'target_year',
        'target_date',
        'age',
        'rule_code',
        'rule_name',
        'status',
        'error_code',
        'message',
        'context_json',
    ];

    protected function casts(): array
    {
        return [
            'target_date' => 'date',
            'context_json' => 'array',
        ];
    }
}
