<?php

namespace App\Services;

use App\Models\GalleryVideo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class GalleryVideoChunkUploadService
{
    public const TEMP_DISK = 'gallery_temp';

    public const FINAL_DIRECTORY = GalleryPublicMediaService::VIDEO_DIRECTORY;

    public function __construct(private readonly GalleryPublicMediaService $media)
    {
    }

    public function storeChunk(
        string $uploadId,
        int $chunkIndex,
        int $totalChunks,
        string $originalFilename,
        int $originalSize,
        UploadedFile $chunk,
        int $adminId,
    ): void {
        $uploadId = $this->safeUploadId($uploadId);
        $this->cleanupAbandoned();

        $this->assertChunkBounds($chunkIndex, $totalChunks, $originalSize, $chunk);
        $extension = $this->safeExtension($originalFilename);

        $manifest = $this->readManifest($uploadId);
        if ($manifest) {
            $this->assertManifestMatches($manifest, $totalChunks, $originalSize, $extension, $adminId);
        } else {
            $this->writeManifest($uploadId, [
                'upload_id' => $uploadId,
                'original_filename' => basename($originalFilename),
                'extension' => $extension,
                'original_size' => $originalSize,
                'total_chunks' => $totalChunks,
                'admin_id' => $adminId,
                'created_at' => now()->toIso8601String(),
            ]);
        }

        Storage::disk(self::TEMP_DISK)->putFileAs(
            $this->chunkDirectory($uploadId),
            $chunk,
            $chunkIndex.'.part'
        );
    }

    public function finalize(string $uploadId, int $totalChunks, int $originalSize, int $adminId): array
    {
        $uploadId = $this->safeUploadId($uploadId);
        $manifest = $this->readManifest($uploadId);
        if (! $manifest) {
            throw ValidationException::withMessages([
                'upload_id' => 'Upload session was not found. Please try the video upload again.',
            ]);
        }

        $this->assertManifestMatches($manifest, $totalChunks, $originalSize, $manifest['extension'], $adminId);

        $chunkSizes = [];
        for ($index = 0; $index < $totalChunks; $index++) {
            $chunkPath = $this->chunkPath($uploadId, $index);
            if (! Storage::disk(self::TEMP_DISK)->exists($chunkPath)) {
                throw ValidationException::withMessages([
                    'upload_id' => 'Video upload is incomplete. Please try again.',
                ]);
            }
            $chunkSizes[] = (int) Storage::disk(self::TEMP_DISK)->size($chunkPath);
        }

        if ((int) array_sum($chunkSizes) !== $originalSize) {
            $this->deleteUploadDirectory($uploadId);
            throw ValidationException::withMessages([
                'upload_id' => 'Uploaded video chunks do not match the original file size.',
            ]);
        }

        $finalRelativePath = $this->media->uniqueRelativePath(self::FINAL_DIRECTORY, $manifest['extension']);
        $finalFullPath = $this->media->absolutePath($finalRelativePath);

        try {
            $output = $this->media->openWriteStream($finalRelativePath);
        } catch (\Throwable) {
            $this->deleteUploadDirectory($uploadId);
            throw ValidationException::withMessages([
                'upload_id' => 'Unable to create the assembled video file.',
            ]);
        }

        try {
            for ($index = 0; $index < $totalChunks; $index++) {
                $input = fopen(Storage::disk(self::TEMP_DISK)->path($this->chunkPath($uploadId, $index)), 'rb');
                if ($input === false) {
                    throw ValidationException::withMessages([
                        'upload_id' => 'Unable to read an uploaded video chunk.',
                    ]);
                }
                stream_copy_to_stream($input, $output);
                fclose($input);
            }
        } catch (\Throwable $exception) {
            fclose($output);
            $this->media->delete($finalRelativePath);
            $this->deleteUploadDirectory($uploadId);
            throw $exception;
        }

        fclose($output);

        $assembledSize = (int) filesize($finalFullPath);
        if ($assembledSize !== $originalSize) {
            $this->media->delete($finalRelativePath);
            $this->deleteUploadDirectory($uploadId);
            throw ValidationException::withMessages([
                'upload_id' => 'Assembled video size does not match the original file.',
            ]);
        }

        $this->deleteUploadDirectory($uploadId);
        $this->writeFinalizedMarker($uploadId, [
            'upload_id' => $uploadId,
            'video_path' => $finalRelativePath,
            'size' => $assembledSize,
            'admin_id' => $adminId,
            'finalized_at' => now()->toIso8601String(),
        ]);

        return [
            'upload_id' => $uploadId,
            'video_path' => $finalRelativePath,
            'size' => $assembledSize,
        ];
    }

    public function consumeFinalizedPath(string $uploadId, int $adminId): string
    {
        $uploadId = $this->safeUploadId($uploadId);
        $marker = $this->readFinalizedMarker($uploadId);
        if (! $marker || (int) $marker['admin_id'] !== $adminId) {
            throw ValidationException::withMessages([
                'upload_id' => 'Finalized video upload was not found. Please upload the video again.',
            ]);
        }

        $path = (string) $marker['video_path'];
        if (! $this->media->isSafeVideoPath($path) || ! $this->media->exists($path)) {
            $this->deleteFinalizedMarker($uploadId);
            throw ValidationException::withMessages([
                'upload_id' => 'Finalized video file is missing. Please upload the video again.',
            ]);
        }

        $this->deleteFinalizedMarker($uploadId);

        return $path;
    }

    public function deletePublicVideo(string $path): void
    {
        $this->media->delete($path);
    }

    public function cleanupAbandoned(): int
    {
        $removed = 0;
        $cutoff = now()->subHours((int) config('gallery.abandoned_after_hours', 24))->getTimestamp();

        try {
            $disk = Storage::disk(self::TEMP_DISK);
        } catch (\Throwable) {
            return 0;
        }

        if ($disk->directoryExists('chunks')) {
            foreach ($disk->directories('chunks') as $directory) {
                $lastModified = $this->directoryAgeTimestamp($directory);
                if ($lastModified !== null && $lastModified < $cutoff) {
                    $disk->deleteDirectory($directory);
                    $removed++;
                }
            }
        }

        if ($disk->directoryExists('finalized')) {
            foreach ($disk->files('finalized') as $file) {
                $lastModified = $disk->lastModified($file);
                if ($lastModified === false || $lastModified >= $cutoff) {
                    continue;
                }

                $marker = json_decode((string) $disk->get($file), true);
                $path = is_array($marker) ? (string) ($marker['video_path'] ?? '') : '';
                if ($path && $this->media->isSafeVideoPath($path) && ! GalleryVideo::query()->where('video_path', $path)->exists()) {
                    $this->media->delete($path);
                }
                $disk->delete($file);
                $removed++;
            }
        }

        return $removed;
    }

    public function isSafePublicVideoPath(string $path): bool
    {
        return $this->media->isSafeVideoPath($path);
    }

    private function assertChunkBounds(int $chunkIndex, int $totalChunks, int $originalSize, UploadedFile $chunk): void
    {
        $maxBytes = (int) config('gallery.max_bytes', 500 * 1024 * 1024);
        $maxChunkBytes = (int) config('gallery.max_chunk_bytes', 6 * 1024 * 1024);
        $minChunks = max(1, (int) ceil($originalSize / $maxChunkBytes));

        if ($originalSize < 1 || $originalSize > $maxBytes) {
            throw ValidationException::withMessages([
                'original_size' => 'Video must be 500 MB or smaller.',
            ]);
        }

        if ($totalChunks < $minChunks || $totalChunks > 400 || $chunkIndex < 0 || $chunkIndex >= $totalChunks) {
            throw ValidationException::withMessages([
                'chunk_index' => 'Invalid video chunk.',
            ]);
        }

        if ($chunk->getSize() < 1 || $chunk->getSize() > $maxChunkBytes) {
            throw ValidationException::withMessages([
                'chunk' => 'Video chunk is larger than allowed.',
            ]);
        }
    }

    private function safeUploadId(string $uploadId): string
    {
        $normalized = strtolower(trim($uploadId));
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $normalized)) {
            throw ValidationException::withMessages([
                'upload_id' => 'Invalid upload session.',
            ]);
        }

        return $normalized;
    }

    private function safeExtension(string $originalFilename): string
    {
        $extension = strtolower((string) pathinfo(basename($originalFilename), PATHINFO_EXTENSION));
        $allowed = config('gallery.allowed_extensions', ['mp4', 'm4v', 'webm', 'mov']);
        if (! in_array($extension, $allowed, true)) {
            throw ValidationException::withMessages([
                'original_filename' => 'Video must be MP4, M4V, WEBM, or MOV.',
            ]);
        }

        return $extension;
    }

    private function chunkDirectory(string $uploadId): string
    {
        return 'chunks/'.$uploadId;
    }

    private function chunkPath(string $uploadId, int $index): string
    {
        return $this->chunkDirectory($uploadId).'/'.$index.'.part';
    }

    private function manifestPath(string $uploadId): string
    {
        return $this->chunkDirectory($uploadId).'/manifest.json';
    }

    private function finalizedPath(string $uploadId): string
    {
        return 'finalized/'.$uploadId.'.json';
    }

    private function writeManifest(string $uploadId, array $manifest): void
    {
        Storage::disk(self::TEMP_DISK)->put(
            $this->manifestPath($uploadId),
            json_encode($manifest, JSON_THROW_ON_ERROR)
        );
    }

    private function readManifest(string $uploadId): ?array
    {
        $path = $this->manifestPath($uploadId);
        if (! Storage::disk(self::TEMP_DISK)->exists($path)) {
            return null;
        }

        $decoded = json_decode((string) Storage::disk(self::TEMP_DISK)->get($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function writeFinalizedMarker(string $uploadId, array $marker): void
    {
        Storage::disk(self::TEMP_DISK)->put(
            $this->finalizedPath($uploadId),
            json_encode($marker, JSON_THROW_ON_ERROR)
        );
    }

    private function readFinalizedMarker(string $uploadId): ?array
    {
        $path = $this->finalizedPath($uploadId);
        if (! Storage::disk(self::TEMP_DISK)->exists($path)) {
            return null;
        }

        $decoded = json_decode((string) Storage::disk(self::TEMP_DISK)->get($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function deleteFinalizedMarker(string $uploadId): void
    {
        Storage::disk(self::TEMP_DISK)->delete($this->finalizedPath($uploadId));
    }

    private function deleteUploadDirectory(string $uploadId): void
    {
        Storage::disk(self::TEMP_DISK)->deleteDirectory($this->chunkDirectory($uploadId));
    }

    private function directoryAgeTimestamp(string $directory): ?int
    {
        $disk = Storage::disk(self::TEMP_DISK);
        $manifestPath = $directory.'/manifest.json';
        if ($disk->exists($manifestPath)) {
            $modified = $disk->lastModified($manifestPath);

            return $modified === false ? null : $modified;
        }

        $oldest = null;
        foreach ($disk->files($directory) as $file) {
            $modified = $disk->lastModified($file);
            if ($modified === false) {
                continue;
            }
            if ($oldest === null || $modified < $oldest) {
                $oldest = $modified;
            }
        }

        return $oldest;
    }

    private function assertManifestMatches(array $manifest, int $totalChunks, int $originalSize, string $extension, int $adminId): void
    {
        if ((int) $manifest['admin_id'] !== $adminId) {
            throw ValidationException::withMessages([
                'upload_id' => 'Invalid upload session.',
            ]);
        }

        if ((int) $manifest['total_chunks'] !== $totalChunks
            || (int) $manifest['original_size'] !== $originalSize
            || ($manifest['extension'] ?? '') !== $extension) {
            throw ValidationException::withMessages([
                'upload_id' => 'Upload session details do not match this video.',
            ]);
        }
    }
}
