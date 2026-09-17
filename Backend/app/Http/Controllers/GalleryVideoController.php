<?php

namespace App\Http\Controllers;

use App\Models\GalleryVideo;
use App\Models\GalleryVideoEvent;
use App\Services\GalleryVideoLibraryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GalleryVideoController extends Controller
{
    public function index(Request $request, GalleryVideoLibraryService $service): JsonResponse
    {
        $recentLimit = (int) $request->integer('recent_limit', 2);
        $homeLimit = (int) $request->integer('home_limit', 3);

        return response()->json([
            'success' => true,
            'data' => $service->publicPayload(
                max(0, min($recentLimit, 12)),
                max(0, min($homeLimit, 12)),
                $request
            ),
        ]);
    }

    public function view(Request $request, GalleryVideo $galleryVideo, GalleryVideoLibraryService $service): JsonResponse
    {
        abort_unless($this->isPublicVideo($galleryVideo), 404);

        $identity = $this->viewerIdentity($request);
        $recentView = GalleryVideoEvent::query()
            ->where('gallery_video_id', $galleryVideo->id)
            ->where('event_type', GalleryVideoEvent::TYPE_VIEW)
            ->where('created_at', '>=', now()->subDay())
            ->where(function ($query) use ($identity): void {
                if ($identity['user_id']) {
                    $query->where('user_id', $identity['user_id']);
                    return;
                }
                if ($identity['visitor_key']) {
                    $query->where('visitor_key', $identity['visitor_key']);
                    return;
                }
                $query->where('ip_hash', $identity['ip_hash']);
            })
            ->exists();

        if (! $recentView) {
            GalleryVideoEvent::query()->create([
                'gallery_video_id' => $galleryVideo->id,
                'event_type' => GalleryVideoEvent::TYPE_VIEW,
                ...$identity,
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $service->publicVideoPayload($galleryVideo->fresh(), $request),
        ]);
    }

    public function like(Request $request, GalleryVideo $galleryVideo, GalleryVideoLibraryService $service): JsonResponse
    {
        abort_unless($this->isPublicVideo($galleryVideo), 404);

        $identity = $this->viewerIdentity($request);
        $existing = GalleryVideoEvent::query()
            ->where('gallery_video_id', $galleryVideo->id)
            ->where('event_type', GalleryVideoEvent::TYPE_LIKE)
            ->where(function ($query) use ($identity): void {
                if ($identity['user_id']) {
                    $query->where('user_id', $identity['user_id']);
                    return;
                }
                if ($identity['visitor_key']) {
                    $query->where('visitor_key', $identity['visitor_key']);
                    return;
                }
                $query->where('ip_hash', $identity['ip_hash']);
            })
            ->first();

        if ($existing) {
            $existing->delete();
        } else {
            GalleryVideoEvent::query()->create([
                'gallery_video_id' => $galleryVideo->id,
                'event_type' => GalleryVideoEvent::TYPE_LIKE,
                ...$identity,
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $service->publicVideoPayload($galleryVideo->fresh(), $request),
        ]);
    }

    private function isPublicVideo(GalleryVideo $video): bool
    {
        return (bool) $video->is_active
            && $video->publication_status === GalleryVideo::STATUS_PUBLISHED
            && $video->published_at !== null;
    }

    private function viewerIdentity(Request $request): array
    {
        $visitorKey = $request->header('X-Gallery-Visitor');
        $visitorKey = is_string($visitorKey) && preg_match('/^[A-Za-z0-9_-]{16,80}$/', $visitorKey) ? $visitorKey : null;

        return [
            'user_id' => $request->user()?->id,
            'visitor_key' => $visitorKey,
            'ip_hash' => hash('sha256', (string) $request->ip().'|'.config('app.key')),
        ];
    }
}
