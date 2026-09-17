<?php

namespace App\Models;

use App\Services\GalleryPublicMediaService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class GalleryVideo extends Model
{
    public const TYPE_SHORT = 'SHORT';
    public const TYPE_WIDE = 'WIDE';
    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_PUBLISHED = 'PUBLISHED';

    public const TYPES = [
        self::TYPE_SHORT,
        self::TYPE_WIDE,
    ];

    public const PUBLICATION_STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PUBLISHED,
    ];

    protected $fillable = [
        'title',
        'description',
        'category',
        'video_type',
        'video_path',
        'thumbnail_path',
        'display_order',
        'publication_status',
        'is_active',
        'published_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'display_order' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    public function videoUrl(): string
    {
        return app(GalleryPublicMediaService::class)->publicUrl($this->video_path) ?? '';
    }

    public function thumbnailUrl(): ?string
    {
        return app(GalleryPublicMediaService::class)->publicUrl($this->thumbnail_path);
    }

    public function isPubliclyShareable(): bool
    {
        return (bool) $this->is_active
            && $this->publication_status === self::STATUS_PUBLISHED
            && $this->published_at !== null;
    }

    public function shareSlug(): string
    {
        $slug = Str::slug((string) $this->title);

        return $slug !== '' ? $slug : 'video';
    }

    public function sharePath(): string
    {
        return '/videos/'.$this->id.'/'.$this->shareSlug();
    }

    public function shareUrl(): string
    {
        return rtrim((string) config('app.frontend_url'), '/').$this->sharePath();
    }

    public function absoluteThumbnailUrl(): ?string
    {
        return $this->thumbnailUrl();
    }
}
