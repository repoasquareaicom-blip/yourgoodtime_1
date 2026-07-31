<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TempleRemedyRequest extends Model
{
    protected $fillable = [
        'user_id',
        'problem_text',
        'status',
        'payment_enabled',
        'amount_minor',
        'currency',
        'payment_status',
        'payment_enabled_at',
        'paid_at',
        'remedy_type',
        'remedy_title',
        'remedy_file_path',
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
            'payment_enabled_at' => 'datetime',
            'paid_at' => 'datetime',
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
