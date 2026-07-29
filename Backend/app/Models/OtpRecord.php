<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OtpRecord extends Model
{
    protected $fillable = [
        'email',
        'purpose',
        'otp_hash',
        'expires_at',
        'used_at',
        'pending_payload',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'pending_payload' => 'array',
        ];
    }
}
