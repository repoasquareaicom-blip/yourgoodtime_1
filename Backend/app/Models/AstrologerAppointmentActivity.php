<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AstrologerAppointmentActivity extends Model
{
    protected $fillable = [
        'appointment_id',
        'actor_user_id',
        'actor_admin_id',
        'event_type',
        'title',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(AstrologerAppointment::class, 'appointment_id');
    }
}
