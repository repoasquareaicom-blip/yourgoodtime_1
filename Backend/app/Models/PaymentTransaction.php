<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentTransaction extends Model
{
    protected $fillable = [
        'prediction_request_id',
        'temple_remedy_request_id',
        'astrologer_appointment_id',
        'user_id',
        'product_id',
        'provider',
        'currency',
        'amount_minor',
        'tax_inclusive',
        'status',
        'provider_order_id',
        'provider_payment_id',
        'provider_signature',
        'payment_contact',
        'payment_email',
        'failure_code',
        'failure_description',
        'paid_at',
        'verified_at',
        'captured_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'tax_inclusive' => 'boolean',
            'paid_at' => 'datetime',
            'verified_at' => 'datetime',
            'captured_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
