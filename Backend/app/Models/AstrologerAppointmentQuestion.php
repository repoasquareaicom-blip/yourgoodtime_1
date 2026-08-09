<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AstrologerAppointmentQuestion extends Model
{
    protected $fillable = [
        'appointment_id',
        'consultation_question_id',
        'sequence',
        'question',
    ];

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(AstrologerAppointment::class, 'appointment_id');
    }
}
