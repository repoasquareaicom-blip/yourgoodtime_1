<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AstrologerAppointmentSlotBlock extends Model
{
    protected $fillable = [
        'astrologer_id',
        'start_at',
        'end_at',
        'timezone',
        'reason',
        'created_by_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'end_at' => 'datetime',
        ];
    }
}
