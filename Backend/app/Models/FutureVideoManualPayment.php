<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FutureVideoManualPayment extends Model
{
    public const SERVICE_TYPE = 'FUTURE_VIDEO';
    public const PAYMENT_METHOD = 'GPAY_QR';
    public const PAST_SERVICE_TYPE = 'PAST_PREDICTION';
    public const AWAITING = 'AWAITING_SUBMISSION';
    public const PENDING = 'PENDING';
    public const VERIFIED = 'VERIFIED';
    public const REJECTED = 'REJECTED';

    protected $fillable = [
        'user_id',
        'prediction_request_id',
        'product_id',
        'service_type',
        'purchase_reference',
        'amount_minor',
        'currency',
        'payment_method',
        'payment_proof_path',
        'payment_proof_original_name',
        'payment_proof_mime_type',
        'payment_proof_size',
        'status',
        'submitted_at',
        'verified_at',
        'verified_by',
        'rejection_reason',
        'transaction_reference',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'verified_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function predictionRequest(): BelongsTo
    {
        return $this->belongsTo(PredictionRequest::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(PredictionProduct::class);
    }
}
