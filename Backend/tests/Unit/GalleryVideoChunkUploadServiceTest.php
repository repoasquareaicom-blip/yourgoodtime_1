<?php

namespace Tests\Unit;

use App\Services\GalleryVideoChunkUploadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GalleryVideoChunkUploadServiceTest extends TestCase
{
    public function test_finalize_streams_chunks_in_order_and_verifies_size(): void
    {
        Storage::fake('gallery_temp');

        $service = app(GalleryVideoChunkUploadService::class);
        $uploadId = (string) Str::uuid();
        $parts = [str_repeat('1', 1200), str_repeat('2', 800), str_repeat('3', 400)];
        $original = implode('', $parts);

        foreach ($parts as $index => $part) {
            $service->storeChunk(
                $uploadId,
                $index,
                count($parts),
                'ordered.mp4',
                strlen($original),
                UploadedFile::fake()->createWithContent($index.'.part', $part),
                9,
            );
        }

        $result = $service->finalize($uploadId, count($parts), strlen($original), 9);

        $this->assertSame(strlen($original), $result['size']);
        $this->assertSame($original, $this->galleryMedia()->get($result['video_path']));
        $this->assertFalse(Storage::disk('gallery_temp')->exists('chunks/'.$uploadId.'/0.part'));
        $this->assertTrue(Storage::disk('gallery_temp')->exists('finalized/'.$uploadId.'.json'));
    }

    public function test_five_megabyte_chunks_assemble_a_larger_file(): void
    {
        Storage::fake('gallery_temp');

        $service = app(GalleryVideoChunkUploadService::class);
        $uploadId = (string) Str::uuid();
        $chunkSize = 5 * 1024 * 1024;
        $parts = [
            random_bytes($chunkSize),
            random_bytes($chunkSize),
            random_bytes(512 * 1024),
        ];
        $original = implode('', $parts);

        foreach ($parts as $index => $part) {
            $service->storeChunk(
                $uploadId,
                $index,
                count($parts),
                'large.mp4',
                strlen($original),
                UploadedFile::fake()->createWithContent($index.'.part', $part),
                9,
            );
        }

        $result = $service->finalize($uploadId, count($parts), strlen($original), 9);

        $this->assertSame(strlen($original), $result['size']);
        $this->assertSame($original, $this->galleryMedia()->get($result['video_path']));
        $this->assertFalse(Storage::disk('gallery_temp')->directoryExists('chunks/'.$uploadId));
    }

    public function test_seventy_megabyte_file_assembles_from_five_megabyte_chunks(): void
    {
        Storage::fake('gallery_temp');

        $service = app(GalleryVideoChunkUploadService::class);
        $uploadId = (string) Str::uuid();
        $chunkSize = 5 * 1024 * 1024;
        $remainder = 2 * 1024 * 1024;
        $parts = [];
        $source = tmpfile();
        $sourcePath = stream_get_meta_data($source)['uri'];

        for ($index = 0; $index < 14; $index++) {
            $part = random_bytes($chunkSize);
            $parts[] = $part;
            fwrite($source, $part);
        }
        $tail = random_bytes($remainder);
        $parts[] = $tail;
        fwrite($source, $tail);
        fflush($source);

        $originalSize = filesize($sourcePath);
        $this->assertSame((14 * $chunkSize) + $remainder, $originalSize);

        foreach ($parts as $index => $part) {
            $service->storeChunk(
                $uploadId,
                $index,
                count($parts),
                'large-clip.mp4',
                $originalSize,
                UploadedFile::fake()->createWithContent($index.'.part', $part),
                9,
            );
            $parts[$index] = null;
        }

        $result = $service->finalize($uploadId, 15, $originalSize, 9);

        $this->assertSame($originalSize, $result['size']);
        $this->assertSame($originalSize, $this->galleryMedia()->size($result['video_path']));
        $this->assertSame(hash_file('sha256', $sourcePath), hash_file('sha256', $this->galleryMedia()->absolutePath($result['video_path'])));
        $this->assertFalse(Storage::disk('gallery_temp')->directoryExists('chunks/'.$uploadId));
        fclose($source);
    }

    public function test_finalize_rejects_size_mismatch_without_leaving_public_file(): void
    {
        Storage::fake('gallery_temp');

        $service = app(GalleryVideoChunkUploadService::class);
        $uploadId = (string) Str::uuid();

        $service->storeChunk(
            $uploadId,
            0,
            1,
            'clip.mp4',
            5000,
            UploadedFile::fake()->createWithContent('0.part', str_repeat('A', 1200)),
            9,
        );

        try {
            $service->finalize($uploadId, 1, 5000, 9);
            $this->fail('Size mismatch should fail finalization.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('upload_id', $exception->errors());
        }

        $this->assertSame([], $this->galleryMedia()->files('gallery/videos'));
        $this->assertFalse(Storage::disk('gallery_temp')->exists('chunks/'.$uploadId.'/0.part'));
    }
}
