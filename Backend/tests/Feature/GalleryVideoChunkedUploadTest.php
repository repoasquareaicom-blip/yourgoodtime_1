<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\GalleryVideo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class GalleryVideoChunkedUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_gallery_video_from_chunked_upload(): void
    {
        Storage::fake('gallery_temp');

        $admin = $this->admin();
        $uploadId = (string) Str::uuid();
        $contents = [str_repeat('A', 4000), str_repeat('B', 2500)];
        $original = implode('', $contents);

        $this->uploadChunks($admin, $uploadId, $contents, 'clip.mp4');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/gallery-videos/finalize-upload', [
                'upload_id' => $uploadId,
                'total_chunks' => 2,
                'original_size' => strlen($original),
                'original_filename' => 'clip.mp4',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertFalse(Storage::disk('gallery_temp')->exists('chunks/'.$uploadId.'/0.part'));

        $this->actingAs($admin, 'sanctum')
            ->post('/api/admin/gallery-videos', [
                'title' => 'Chunked Video',
                'description' => 'Uploaded in pieces.',
                'category' => 'Career',
                'video_type' => GalleryVideo::TYPE_WIDE,
                'display_order' => 3,
                'publication_status' => GalleryVideo::STATUS_PUBLISHED,
                'is_active' => true,
                'upload_id' => $uploadId,
                'thumbnail_file' => UploadedFile::fake()->image('thumb.jpg', 640, 360),
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Chunked Video')
            ->assertJsonPath('data.category', 'Career');

        $video = GalleryVideo::query()->firstOrFail();
        $this->assertTrue($this->galleryMedia()->exists($video->video_path));
        $this->assertSame($original, $this->galleryMedia()->get($video->video_path));
        $this->assertTrue($this->galleryMedia()->exists($video->thumbnail_path));
        $this->assertStringStartsWith('http://localhost/gallery/videos/', $video->videoUrl());
        $this->assertStringStartsWith('http://localhost/gallery/thumbnails/', (string) $video->thumbnailUrl());
        $this->assertStringNotContainsString('/storage/', $video->videoUrl());
        $this->assertFalse(Storage::disk('gallery_temp')->exists('finalized/'.$uploadId.'.json'));
    }

    public function test_edit_without_replacement_keeps_existing_video_path(): void
    {
        $admin = $this->admin();
        $video = $this->galleryVideo([
            'video_path' => 'gallery/videos/original.mp4',
        ]);
        $this->galleryMedia()->put($video->video_path, 'existing-bytes');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/gallery-videos/{$video->id}", [
                'title' => 'Updated Title',
                'description' => 'No new video.',
                'category' => 'General',
                'video_type' => GalleryVideo::TYPE_WIDE,
                'display_order' => 1,
                'publication_status' => GalleryVideo::STATUS_PUBLISHED,
                'is_active' => true,
            ])
            ->assertOk();

        $this->assertSame('gallery/videos/original.mp4', $video->refresh()->video_path);
        $this->assertSame('existing-bytes', $this->galleryMedia()->get($video->video_path));
    }

    public function test_replacement_updates_record_only_after_new_file_is_finalized(): void
    {
        Storage::fake('gallery_temp');

        $admin = $this->admin();
        $video = $this->galleryVideo([
            'video_path' => 'gallery/videos/old-clip.mp4',
        ]);
        $this->galleryMedia()->put($video->video_path, 'old-video');

        $uploadId = (string) Str::uuid();
        $contents = [str_repeat('N', 3000), str_repeat('W', 1800)];
        $original = implode('', $contents);
        $this->uploadChunks($admin, $uploadId, $contents, 'replacement.mp4');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/gallery-videos/finalize-upload', [
                'upload_id' => $uploadId,
                'total_chunks' => 2,
                'original_size' => strlen($original),
            ])
            ->assertOk();

        $this->actingAs($admin, 'sanctum')
            ->post("/api/admin/gallery-videos/{$video->id}", [
                'title' => $video->title,
                'description' => $video->description,
                'category' => $video->category,
                'video_type' => $video->video_type,
                'display_order' => $video->display_order,
                'publication_status' => GalleryVideo::STATUS_PUBLISHED,
                'is_active' => true,
                'upload_id' => $uploadId,
            ], ['Accept' => 'application/json'])
            ->assertOk();

        $video->refresh();
        $this->assertNotSame('gallery/videos/old-clip.mp4', $video->video_path);
        $this->assertFalse($this->galleryMedia()->exists('gallery/videos/old-clip.mp4'));
        $this->assertTrue($this->galleryMedia()->exists($video->video_path));
        $this->assertSame($original, $this->galleryMedia()->get($video->video_path));
    }

    public function test_failed_finalization_does_not_create_gallery_record(): void
    {
        Storage::fake('gallery_temp');

        $admin = $this->admin();
        $uploadId = (string) Str::uuid();
        $this->uploadChunks($admin, $uploadId, [str_repeat('A', 2000)], 'clip.mp4', originalSize: 5000);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/gallery-videos/finalize-upload', [
                'upload_id' => $uploadId,
                'total_chunks' => 1,
                'original_size' => 5000,
            ])
            ->assertUnprocessable();

        $this->assertSame(0, GalleryVideo::query()->count());
        $this->assertSame([], $this->galleryMedia()->files('gallery/videos'));
    }

    public function test_missing_chunk_prevents_finalization(): void
    {
        Storage::fake('gallery_temp');

        $admin = $this->admin();
        $uploadId = (string) Str::uuid();
        $this->actingAs($admin, 'sanctum')
            ->post('/api/admin/gallery-videos/upload-chunk', [
                'upload_id' => $uploadId,
                'chunk_index' => 0,
                'total_chunks' => 2,
                'original_filename' => 'clip.mp4',
                'original_size' => 4000,
                'chunk' => UploadedFile::fake()->createWithContent('0.part', str_repeat('A', 2000)),
            ], ['Accept' => 'application/json'])
            ->assertOk();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/gallery-videos/finalize-upload', [
                'upload_id' => $uploadId,
                'total_chunks' => 2,
                'original_size' => 4000,
            ])
            ->assertUnprocessable();

        $this->assertSame(0, GalleryVideo::query()->count());
    }

    public function test_invalid_upload_id_and_path_traversal_are_rejected(): void
    {
        Storage::fake('gallery_temp');
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->post('/api/admin/gallery-videos/upload-chunk', [
                'upload_id' => '../secret',
                'chunk_index' => 0,
                'total_chunks' => 1,
                'original_filename' => 'clip.mp4',
                'original_size' => 100,
                'chunk' => UploadedFile::fake()->createWithContent('0.part', str_repeat('A', 100)),
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable();

        $this->actingAs($admin, 'sanctum')
            ->post('/api/admin/gallery-videos/upload-chunk', [
                'upload_id' => 'not-a-uuid',
                'chunk_index' => 0,
                'total_chunks' => 1,
                'original_filename' => '../../clip.mp4',
                'original_size' => 100,
                'chunk' => UploadedFile::fake()->createWithContent('0.part', str_repeat('A', 100)),
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable();
    }

    public function test_chunk_endpoints_require_admin_authentication(): void
    {
        $this->postJson('/api/admin/gallery-videos/upload-chunk', [])
            ->assertUnauthorized();

        $this->postJson('/api/admin/gallery-videos/finalize-upload', [])
            ->assertUnauthorized();

        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/admin/gallery-videos/upload-chunk', [])
            ->assertForbidden();
    }

    public function test_abandoned_chunk_directories_are_cleaned_up(): void
    {
        Storage::fake('gallery_temp');
        $uploadId = (string) Str::uuid();
        $directory = 'chunks/'.$uploadId;
        Storage::disk('gallery_temp')->put($directory.'/0.part', 'stale');
        Storage::disk('gallery_temp')->put($directory.'/manifest.json', json_encode([
            'upload_id' => $uploadId,
            'created_at' => now()->subDays(2)->toIso8601String(),
        ]));

        $fullPath = Storage::disk('gallery_temp')->path($directory.'/manifest.json');
        touch($fullPath, now()->subHours(30)->getTimestamp());

        $removed = app(\App\Services\GalleryVideoChunkUploadService::class)->cleanupAbandoned();

        $this->assertGreaterThan(0, $removed);
        $this->assertFalse(Storage::disk('gallery_temp')->exists($directory.'/0.part'));
    }

    private function uploadChunks(AdminUser $admin, string $uploadId, array $contents, string $filename, ?int $originalSize = null): void
    {
        $size = $originalSize ?? strlen(implode('', $contents));

        foreach ($contents as $index => $content) {
            $this->actingAs($admin, 'sanctum')
                ->post('/api/admin/gallery-videos/upload-chunk', [
                    'upload_id' => $uploadId,
                    'chunk_index' => $index,
                    'total_chunks' => count($contents),
                    'original_filename' => $filename,
                    'original_size' => $size,
                    'chunk' => UploadedFile::fake()->createWithContent($index.'.part', $content),
                ], ['Accept' => 'application/json'])
                ->assertOk();
        }
    }

    private function admin(): AdminUser
    {
        return AdminUser::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
    }

    private function galleryVideo(array $overrides = []): GalleryVideo
    {
        return GalleryVideo::query()->create([
            'title' => $overrides['title'] ?? 'Gallery Video',
            'description' => $overrides['description'] ?? 'Video description',
            'category' => $overrides['category'] ?? 'General',
            'video_type' => $overrides['video_type'] ?? GalleryVideo::TYPE_WIDE,
            'video_path' => $overrides['video_path'] ?? 'gallery/videos/test.mp4',
            'thumbnail_path' => $overrides['thumbnail_path'] ?? 'gallery/thumbnails/test.jpg',
            'display_order' => $overrides['display_order'] ?? 0,
            'publication_status' => $overrides['publication_status'] ?? GalleryVideo::STATUS_PUBLISHED,
            'is_active' => $overrides['is_active'] ?? true,
            'published_at' => $overrides['published_at'] ?? now(),
            'created_by' => $overrides['created_by'] ?? null,
        ]);
    }
}
