<?php

namespace App\Http\Controllers;

use App\Models\GalleryVideo;
use App\Services\GalleryPublicMediaService;
use App\Services\GalleryVideoChunkUploadService;
use App\Services\GalleryVideoLibraryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminGalleryVideoController extends Controller
{
    public function index(GalleryVideoLibraryService $service): JsonResponse
    {
        $videos = GalleryVideo::query()
            ->orderBy('display_order')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (GalleryVideo $video): array => $service->adminVideoPayload($video))
            ->values();

        return response()->json([
            'success' => true,
            'data' => $videos,
        ]);
    }

    public function show(GalleryVideo $galleryVideo, GalleryVideoLibraryService $service): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $service->adminVideoPayload($galleryVideo),
        ]);
    }

    public function uploadChunk(Request $request, GalleryVideoChunkUploadService $uploads): JsonResponse
    {
        $validated = $request->validate([
            'upload_id' => ['required', 'uuid'],
            'chunk_index' => ['required', 'integer', 'min:0'],
            'total_chunks' => ['required', 'integer', 'min:1', 'max:400'],
            'original_filename' => ['required', 'string', 'max:255'],
            'original_size' => ['required', 'integer', 'min:1'],
            'chunk' => ['required', 'file'],
        ]);

        $uploads->storeChunk(
            $validated['upload_id'],
            (int) $validated['chunk_index'],
            (int) $validated['total_chunks'],
            $validated['original_filename'],
            (int) $validated['original_size'],
            $request->file('chunk'),
            (int) $request->user()->id,
        );

        return response()->json([
            'success' => true,
            'data' => [
                'upload_id' => $validated['upload_id'],
                'chunk_index' => (int) $validated['chunk_index'],
                'total_chunks' => (int) $validated['total_chunks'],
            ],
        ]);
    }

    public function finalizeUpload(Request $request, GalleryVideoChunkUploadService $uploads): JsonResponse
    {
        $validated = $request->validate([
            'upload_id' => ['required', 'uuid'],
            'total_chunks' => ['required', 'integer', 'min:1', 'max:400'],
            'original_size' => ['required', 'integer', 'min:1'],
            'original_filename' => ['nullable', 'string', 'max:255'],
        ]);

        $result = $uploads->finalize(
            $validated['upload_id'],
            (int) $validated['total_chunks'],
            (int) $validated['original_size'],
            (int) $request->user()->id,
        );

        return response()->json([
            'success' => true,
            'message' => 'Video uploaded successfully.',
            'data' => $result,
        ]);
    }

    public function store(
        Request $request,
        GalleryVideoLibraryService $service,
        GalleryVideoChunkUploadService $uploads,
        GalleryPublicMediaService $media,
    ): JsonResponse {
        $validated = $this->validatedData($request, true);
        $videoPath = $this->resolveIncomingVideoPath($request, $uploads, $media, true);
        $thumbnailPath = $request->hasFile('thumbnail_file')
            ? $media->storeUpload($request->file('thumbnail_file'), 'thumbnail')
            : null;

        try {
            $video = GalleryVideo::query()->create([
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'category' => $validated['category'] ?? 'General',
                'video_type' => $validated['video_type'],
                'video_path' => $videoPath,
                'thumbnail_path' => $thumbnailPath,
                'display_order' => (int) ($validated['display_order'] ?? 0),
                'publication_status' => $validated['publication_status'],
                'is_active' => (bool) ($validated['is_active'] ?? true),
                'published_at' => $validated['publication_status'] === GalleryVideo::STATUS_PUBLISHED ? now() : null,
                'created_by' => $request->user()?->id,
            ]);
        } catch (\Throwable $exception) {
            if ($request->hasFile('video_file') || $request->filled('upload_id')) {
                $media->delete($videoPath);
            }
            $media->delete($thumbnailPath);
            throw $exception;
        }

        return response()->json([
            'success' => true,
            'message' => 'Gallery video saved.',
            'data' => $service->adminVideoPayload($video),
        ], 201);
    }

    public function update(
        Request $request,
        GalleryVideo $galleryVideo,
        GalleryVideoLibraryService $service,
        GalleryVideoChunkUploadService $uploads,
        GalleryPublicMediaService $media,
    ): JsonResponse {
        $validated = $this->validatedData($request, false);
        $oldVideoPath = $galleryVideo->video_path;
        $oldThumbnailPath = $galleryVideo->thumbnail_path;
        $newVideoPath = $this->resolveIncomingVideoPath($request, $uploads, $media, false);
        $newThumbnailPath = $request->hasFile('thumbnail_file')
            ? $media->storeUpload($request->file('thumbnail_file'), 'thumbnail')
            : null;

        try {
            DB::transaction(function () use ($galleryVideo, $validated, $newVideoPath, $newThumbnailPath): void {
                $wasPublishedAt = $galleryVideo->published_at;
                $isActive = array_key_exists('is_active', $validated)
                    ? (bool) $validated['is_active']
                    : (bool) $galleryVideo->is_active;
                $publicationStatus = $validated['publication_status'] ?? $galleryVideo->publication_status ?? GalleryVideo::STATUS_DRAFT;

                $galleryVideo->fill([
                    'title' => $validated['title'],
                    'description' => $validated['description'] ?? null,
                    'category' => $validated['category'] ?? 'General',
                    'video_type' => $validated['video_type'],
                    'display_order' => (int) ($validated['display_order'] ?? 0),
                    'publication_status' => $publicationStatus,
                    'is_active' => $isActive,
                    'video_path' => $newVideoPath ?: $galleryVideo->video_path,
                    'thumbnail_path' => $newThumbnailPath ?: $galleryVideo->thumbnail_path,
                ]);

                if ($publicationStatus === GalleryVideo::STATUS_PUBLISHED && ! $wasPublishedAt) {
                    $galleryVideo->published_at = now();
                }

                $galleryVideo->save();
            });
        } catch (\Throwable $exception) {
            $media->delete($newVideoPath);
            $media->delete($newThumbnailPath);
            throw $exception;
        }

        if ($newVideoPath && $oldVideoPath && $oldVideoPath !== $newVideoPath) {
            $media->delete($oldVideoPath);
        }

        if ($newThumbnailPath && $oldThumbnailPath && $oldThumbnailPath !== $newThumbnailPath) {
            $media->delete($oldThumbnailPath);
        }

        return response()->json([
            'success' => true,
            'message' => 'Gallery video updated.',
            'data' => $service->adminVideoPayload($galleryVideo->fresh()),
        ]);
    }

    public function visibility(Request $request, GalleryVideo $galleryVideo, GalleryVideoLibraryService $service): JsonResponse
    {
        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $galleryVideo->is_active = (bool) $validated['is_active'];
        if ($galleryVideo->is_active && $galleryVideo->publication_status === GalleryVideo::STATUS_PUBLISHED && ! $galleryVideo->published_at) {
            $galleryVideo->published_at = now();
        }
        $galleryVideo->save();

        return response()->json([
            'success' => true,
            'message' => $galleryVideo->is_active ? 'Gallery video is visible.' : 'Gallery video is hidden.',
            'data' => $service->adminVideoPayload($galleryVideo->fresh()),
        ]);
    }

    public function destroy(GalleryVideo $galleryVideo, GalleryPublicMediaService $media): JsonResponse
    {
        $videoPath = $galleryVideo->video_path;
        $thumbnailPath = $galleryVideo->thumbnail_path;
        $galleryVideo->delete();
        $media->delete($videoPath);
        $media->delete($thumbnailPath);

        return response()->json([
            'success' => true,
            'message' => 'Gallery video deleted.',
        ]);
    }

    private function resolveIncomingVideoPath(
        Request $request,
        GalleryVideoChunkUploadService $uploads,
        GalleryPublicMediaService $media,
        bool $isCreate,
    ): ?string {
        if ($request->hasFile('video_file')) {
            return $media->storeUpload($request->file('video_file'), 'video');
        }

        if ($request->filled('upload_id')) {
            return $uploads->consumeFinalizedPath(
                $request->string('upload_id')->toString(),
                (int) $request->user()->id,
            );
        }

        if ($isCreate) {
            throw ValidationException::withMessages([
                'video_file' => 'Choose a video file before saving.',
            ]);
        }

        return null;
    }

    private function validatedData(Request $request, bool $isCreate): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category' => ['required', 'string', 'max:120'],
            'video_type' => ['required', Rule::in(GalleryVideo::TYPES)],
            'display_order' => ['nullable', 'integer', 'min:-100000', 'max:100000'],
            'publication_status' => ['required', Rule::in(GalleryVideo::PUBLICATION_STATUSES)],
            'is_active' => ['sometimes', 'boolean'],
            'thumbnail_file' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'upload_id' => ['nullable', 'uuid'],
            'video_file' => ['nullable', 'file', 'mimes:mp4,m4v,webm,mov', 'max:512000'],
        ], [
            'thumbnail_file.mimes' => 'Thumbnail must be a JPG, PNG, or WEBP image.',
            'thumbnail_file.uploaded' => 'Thumbnail failed to upload. Check PHP upload limits and the temporary upload folder.',
            'thumbnail_file.max' => 'Thumbnail must be 8 MB or smaller.',
            'video_file.required' => 'Choose a video file before saving.',
            'video_file.uploaded' => 'Video failed to upload because PHP rejected the file before Laravel could save it. Increase upload_max_filesize and post_max_size, then restart the PHP/Laravel server.',
            'video_file.mimes' => 'Video must be MP4, M4V, WEBM, or MOV.',
            'video_file.max' => 'Video must be 500 MB or smaller. If your file is smaller than this, increase PHP upload_max_filesize and post_max_size.',
            'upload_id.uuid' => 'Video upload session is invalid. Please upload the video again.',
        ], [
            'publication_status' => 'publication',
            'video_type' => 'video type',
            'thumbnail_file' => 'thumbnail',
            'video_file' => 'video file',
            'upload_id' => 'video upload',
        ]);
    }
}
