<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TempleRemedyRequest extends Model
{
    protected $fillable = [
        'user_id',
        'prediction_request_id',
        'remedy_option_code',
        'remedy_option_title',
        'problem_text',
        'contact_number',
        'alternate_contact_number',
        'preferred_language',
        'payment_method_code',
        'manual_payment',
        'status',
        'payment_enabled',
        'amount_minor',
        'currency',
        'payment_status',
        'payment_enabled_at',
        'paid_at',
        'completed_at',
        'completed_by_admin_id',
        'completion_method',
        'remedy_type',
        'remedy_title',
        'remedy_file_path',
        'remedy_file_name',
        'remedy_mime_type',
        'remedy_file_size',
        'remedy_url',
        'remedy_uploaded_at',
        'admin_note',
        'feedback_rating',
        'feedback_comments',
        'feedback_submitted_at',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'payment_enabled' => 'boolean',
            'manual_payment' => 'array',
            'payment_enabled_at' => 'datetime',
            'paid_at' => 'datetime',
            'completed_at' => 'datetime',
            'remedy_uploaded_at' => 'datetime',
            'feedback_submitted_at' => 'datetime',
            'submitted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
