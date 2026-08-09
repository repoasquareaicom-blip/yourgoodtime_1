<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppointmentLanguage extends Model
{
    protected $fillable = [
        'name',
        'code',
        'allow_appointment',
        'sort_order',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'allow_appointment' => 'boolean',
            'metadata' => 'array',
        ];
    }
}
