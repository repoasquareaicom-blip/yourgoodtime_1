<?php

namespace App\Services;

use App\Models\GalleryVideo;
use App\Models\GalleryVideoEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class GalleryVideoLibraryService
{
    public function publicPayload(int $recentLimit = 2, int $homeLimit = 3, ?Request $request = null): array
    {
        return [
            'recent' => $this->recent($recentLimit)->map(fn (GalleryVideo $video): array => $this->publicVideoPayload($video, $request))->values(),
            'home' => $this->recent($homeLimit)->map(fn (GalleryVideo $video): array => $this->publicVideoPayload($video, $request))->values(),
            'shorts' => $this->galleryByType(GalleryVideo::TYPE_SHORT)->map(fn (GalleryVideo $video): array => $this->publicVideoPayload($video, $request))->values(),
            'videos' => $this->galleryByType(GalleryVideo::TYPE_WIDE)->map(fn (GalleryVideo $video): array => $this->publicVideoPayload($video, $request))->values(),
        ];
    }

    public function recent(int $limit): Collection
    {
        return $this->activePublished()
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function galleryByType(string $type): Collection
    {
        return $this->activePublished()
            ->where('video_type', $type)
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();
    }

    public function publicVideoPayload(GalleryVideo $video, ?Request $request = null): array
    {
        $visitorKey = $request?->header('X-Gallery-Visitor');
        $userId = $request?->user()?->id;

        return [
            'id' => $video->id,
            'title' => $video->title,
            'description' => $video->description,
            'category' => $video->category ?: 'General',
            'video_type' => $video->video_type,
            'thumbnail_url' => $video->thumbnailUrl(),
            'video_url' => $video->videoUrl(),
            'display_order' => $video->display_order,
            'published_at' => $video->published_at?->toJSON(),
            'view_count' => $this->eventCount($video, GalleryVideoEvent::TYPE_VIEW),
            'like_count' => $this->eventCount($video, GalleryVideoEvent::TYPE_LIKE),
            'liked_by_me' => $this->likedByViewer($video, $visitorKey, $userId),
        ];
    }

    public function adminVideoPayload(GalleryVideo $video): array
    {
        return [
            ...$this->publicVideoPayload($video),
            'thumbnail_path' => $video->thumbnail_path,
            'video_path' => $video->video_path,
            'publication_status' => $video->publication_status ?: GalleryVideo::STATUS_DRAFT,
            'is_active' => (bool) $video->is_active,
            'created_by' => $video->created_by,
            'created_at' => $video->created_at?->toJSON(),
            'updated_at' => $video->updated_at?->toJSON(),
        ];
    }

    private function activePublished(): Builder
    {
        return GalleryVideo::query()
            ->where('is_active', true)
            ->where('publication_status', GalleryVideo::STATUS_PUBLISHED)
            ->whereNotNull('published_at');
    }

    private function eventCount(GalleryVideo $video, string $type): int
    {
        return GalleryVideoEvent::query()
            ->where('gallery_video_id', $video->id)
            ->where('event_type', $type)
            ->count();
    }

    private function likedByViewer(GalleryVideo $video, ?string $visitorKey, ?int $userId): bool
    {
        if (! $visitorKey && ! $userId) {
            return false;
        }

        return GalleryVideoEvent::query()
            ->where('gallery_video_id', $video->id)
            ->where('event_type', GalleryVideoEvent::TYPE_LIKE)
            ->where(function (Builder $query) use ($visitorKey, $userId): void {
                if ($userId) {
                    $query->orWhere('user_id', $userId);
                }
                if ($visitorKey) {
                    $query->orWhere('visitor_key', $visitorKey);
                }
            })
            ->exists();
    }
}
