<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AstrologerConsultationQuestion extends Model
{
    protected $fillable = [
        'title',
        'question',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
