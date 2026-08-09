<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Astrologer extends Model
{
    protected $fillable = [
        'name',
        'display_name',
        'timezone',
        'is_active',
        'sort_order',
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
