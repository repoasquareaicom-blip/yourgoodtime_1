<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AstrologerAppointment extends Model
{
    protected $fillable = [
        'user_id',
        'prediction_request_id',
        'appointment_language_id',
        'astrologer_id',
        'appointment_number',
        'status',
        'notes',
        'contact_number',
        'preferred_date',
        'preferred_time',
        'preferred_timezone',
        'confirmed_start_at',
        'confirmed_end_at',
        'confirmed_timezone',
        'duration_minutes',
        'maximum_questions',
        'amount_minor',
        'currency',
        'tax_inclusive',
        'payment_stage',
        'payment_method_code',
        'payment_status',
        'payment_transaction_id',
        'paid_at',
        'meeting_provider',
        'meeting_link',
        'calendar_event_id',
        'admin_note',
        'confirmed_by_admin_id',
        'confirmed_at',
        'completed_at',
        'cancelled_at',
        'request_acknowledgement_sent_at',
        'slot_email_sent_at',
        'payment_email_sent_at',
        'meeting_email_sent_at',
        'reminder_24h_sent_at',
        'reminder_1h_sent_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'preferred_date' => 'date',
            'confirmed_start_at' => 'datetime',
            'confirmed_end_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'paid_at' => 'datetime',
            'tax_inclusive' => 'boolean',
            'request_acknowledgement_sent_at' => 'datetime',
            'slot_email_sent_at' => 'datetime',
            'payment_email_sent_at' => 'datetime',
            'meeting_email_sent_at' => 'datetime',
            'reminder_24h_sent_at' => 'datetime',
            'reminder_1h_sent_at' => 'datetime',
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

    public function appointmentLanguage(): BelongsTo
    {
        return $this->belongsTo(AppointmentLanguage::class, 'appointment_language_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(AstrologerAppointmentQuestion::class, 'appointment_id')->orderBy('sequence');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(AstrologerAppointmentActivity::class, 'appointment_id')->latest();
    }
}
