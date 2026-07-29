<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentEvent extends Model
{
    protected $fillable = [
        'payment_transaction_id',
        'provider',
        'provider_event_id',
        'event_hash',
        'event_type',
        'payload',
        'signature_verified',
        'processed_at',
        'processing_status',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'signature_verified' => 'boolean',
            'processed_at' => 'datetime',
        ];
    }
}
