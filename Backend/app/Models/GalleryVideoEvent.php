<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GalleryVideoEvent extends Model
{
    public const TYPE_VIEW = 'VIEW';
    public const TYPE_LIKE = 'LIKE';

    protected $fillable = [
        'gallery_video_id',
        'event_type',
        'user_id',
        'visitor_key',
        'ip_hash',
    ];
}
