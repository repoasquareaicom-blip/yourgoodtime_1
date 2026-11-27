<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PredictionEntitlement extends Model
{
    protected $fillable = [
        'prediction_request_id',
        'user_id',
        'product_id',
        'payment_transaction_id',
        'prediction_type',
        'purchased_years',
        'access_start_year',
        'access_end_year',
        'start_age',
        'end_age',
        'allocated_year_count',
        'starts_at',
        'expires_at',
        'purchased_at',
        'status',
        'generated_prediction_result_id',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'purchased_at' => 'datetime',
        ];
    }

    public function paymentTransaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class);
    }

    public function predictionRequest(): BelongsTo
    {
        return $this->belongsTo(PredictionRequest::class);
    }
}
