<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppointmentPaymentMethod extends Model
{
    public const PAYMENT_GATEWAY = 'payment_gateway';
    public const QR_ONLINE = 'qr_online';

    protected $fillable = [
        'code',
        'name',
        'is_active',
        'sort_order',
        'instructions',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }
}
