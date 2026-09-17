<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\GalleryVideo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class GalleryVideoManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_admin_can_create_video_and_first_publish_sets_published_at(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->post('/api/admin/gallery-videos', $this->payload(), ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Admin Video')
            ->assertJsonPath('data.category', 'General')
            ->assertJsonPath('data.publication_status', GalleryVideo::STATUS_PUBLISHED)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.created_by', $admin->id);

        $video = GalleryVideo::query()->firstOrFail();

        $this->assertNotNull($video->published_at);
        $this->assertTrue($this->galleryMedia()->exists($video->video_path));
        $this->assertTrue($this->galleryMedia()->exists($video->thumbnail_path));
        $this->assertStringStartsWith('http://localhost/gallery/videos/', $video->videoUrl());
        $this->assertStringStartsWith('http://localhost/gallery/thumbnails/', $video->thumbnailUrl());
        $this->assertStringNotContainsString('/storage/', $video->videoUrl());
        $this->assertStringNotContainsString('/storage/', (string) $video->thumbnailUrl());
        $this->assertFileExists($this->galleryMedia()->absolutePath($video->thumbnail_path));
        $this->assertFileExists($this->galleryMedia()->absolutePath($video->video_path));
    }

    public function test_unauthorized_user_cannot_manage_gallery_videos(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/gallery-videos')
            ->assertForbidden();
    }

    public function test_edit_preserves_media_and_does_not_change_published_at_when_replacement_absent(): void
    {
        $admin = $this->admin();
        $video = $this->galleryVideo([
            'published_at' => now()->subDays(4),
            'video_path' => 'gallery/videos/original.mp4',
            'thumbnail_path' => 'gallery/thumbnails/original.jpg',
        ]);
        $this->galleryMedia()->put($video->video_path, 'video');
        $this->galleryMedia()->put($video->thumbnail_path, 'thumb');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/gallery-videos/{$video->id}", [
                'title' => 'Updated Title',
                'description' => 'Updated description',
                'category' => 'Updated Category',
                'video_type' => GalleryVideo::TYPE_WIDE,
                'display_order' => 8,
                'publication_status' => GalleryVideo::STATUS_PUBLISHED,
                'is_active' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Updated Title');

        $video->refresh();

        $this->assertSame('gallery/videos/original.mp4', $video->video_path);
        $this->assertSame('gallery/thumbnails/original.jpg', $video->thumbnail_path);
        $this->assertSame('Updated Title', $video->title);
        $this->assertSame('Updated Category', $video->category);
        $this->assertEquals(now()->subDays(4)->toDateTimeString(), $video->published_at->toDateTimeString());
    }

    public function test_hide_does_not_delete_media_and_show_preserves_existing_published_at(): void
    {
        $admin = $this->admin();
        $publishedAt = now()->subDays(2);
        $video = $this->galleryVideo([
            'published_at' => $publishedAt,
            'video_path' => 'gallery/videos/keep.mp4',
            'thumbnail_path' => 'gallery/thumbnails/keep.jpg',
        ]);
        $this->galleryMedia()->put($video->video_path, 'video');
        $this->galleryMedia()->put($video->thumbnail_path, 'thumb');

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/gallery-videos/{$video->id}/visibility", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $video->refresh();
        $this->assertFalse($video->is_active);
        $this->assertEquals($publishedAt->toDateTimeString(), $video->published_at->toDateTimeString());
        $this->assertTrue($this->galleryMedia()->exists($video->video_path));

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/gallery-videos/{$video->id}/visibility", ['is_active' => true])
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $this->assertEquals($publishedAt->toDateTimeString(), $video->refresh()->published_at->toDateTimeString());
    }

    public function test_public_gallery_urls_use_api_public_paths_not_storage(): void
    {
        $video = $this->galleryVideo([
            'video_path' => 'gallery/videos/clip.mp4',
            'thumbnail_path' => 'gallery/thumbnails/cover.jpg',
        ]);
        $this->galleryMedia()->put($video->video_path, 'video-bytes');
        $this->galleryMedia()->put($video->thumbnail_path, 'thumb-bytes');

        $this->getJson('/api/gallery/videos')
            ->assertOk()
            ->assertJsonPath('data.videos.0.video_url', 'http://localhost/gallery/videos/clip.mp4')
            ->assertJsonPath('data.videos.0.thumbnail_url', 'http://localhost/gallery/thumbnails/cover.jpg')
            ->assertDontSee('/storage/gallery/', false);

        $this->assertStringEndsWith(
            DIRECTORY_SEPARATOR.'gallery'.DIRECTORY_SEPARATOR.'videos'.DIRECTORY_SEPARATOR.'clip.mp4',
            $this->galleryMedia()->absolutePath($video->video_path)
        );
        $this->assertStringEndsWith(
            DIRECTORY_SEPARATOR.'gallery'.DIRECTORY_SEPARATOR.'thumbnails'.DIRECTORY_SEPARATOR.'cover.jpg',
            $this->galleryMedia()->absolutePath($video->thumbnail_path)
        );
    }

    public function test_public_endpoint_excludes_hidden_videos_and_filters_by_type_and_display_order(): void
    {
        $this->galleryVideo(['title' => 'Second Short', 'video_type' => GalleryVideo::TYPE_SHORT, 'display_order' => 2]);
        $this->galleryVideo(['title' => 'First Short', 'video_type' => GalleryVideo::TYPE_SHORT, 'display_order' => 1]);
        $this->galleryVideo(['title' => 'First Wide', 'video_type' => GalleryVideo::TYPE_WIDE, 'category' => 'Career', 'display_order' => 1]);
        $this->galleryVideo(['title' => 'Hidden Wide', 'video_type' => GalleryVideo::TYPE_WIDE, 'is_active' => false, 'display_order' => 0]);

        $this->getJson('/api/gallery/videos')
            ->assertOk()
            ->assertJsonPath('data.shorts.0.title', 'First Short')
            ->assertJsonPath('data.shorts.1.title', 'Second Short')
            ->assertJsonPath('data.videos.0.title', 'First Wide')
            ->assertJsonPath('data.videos.0.category', 'Career')
            ->assertJsonMissing(['title' => 'Hidden Wide']);
    }

    public function test_draft_videos_are_admin_previewable_but_excluded_from_public_gallery(): void
    {
        $admin = $this->admin();
        $draft = $this->galleryVideo([
            'title' => 'Draft Only',
            'publication_status' => GalleryVideo::STATUS_DRAFT,
            'published_at' => null,
        ]);

        $this->getJson('/api/gallery/videos')
            ->assertOk()
            ->assertJsonMissing(['title' => 'Draft Only']);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/gallery-videos/{$draft->id}")
            ->assertOk()
            ->assertJsonPath('data.title', 'Draft Only')
            ->assertJsonPath('data.publication_status', GalleryVideo::STATUS_DRAFT);
    }

    public function test_publishing_existing_draft_sets_published_at_once(): void
    {
        $admin = $this->admin();
        $draft = $this->galleryVideo([
            'publication_status' => GalleryVideo::STATUS_DRAFT,
            'published_at' => null,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/gallery-videos/{$draft->id}", [
                'title' => $draft->title,
                'description' => $draft->description,
                'category' => $draft->category,
                'video_type' => $draft->video_type,
                'display_order' => $draft->display_order,
                'publication_status' => GalleryVideo::STATUS_PUBLISHED,
                'is_active' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.publication_status', GalleryVideo::STATUS_PUBLISHED);

        $this->assertNotNull($draft->refresh()->published_at);
    }

    public function test_recent_and_home_use_latest_active_published_videos_with_hidden_fallback(): void
    {
        $this->galleryVideo(['title' => 'A', 'published_at' => now()->subDays(4)]);
        $this->galleryVideo(['title' => 'B', 'published_at' => now()->subDays(3)]);
        $this->galleryVideo(['title' => 'C', 'published_at' => now()->subDays(2)]);
        $this->galleryVideo(['title' => 'D', 'published_at' => now()->subDay()]);
        $this->galleryVideo(['title' => 'E', 'published_at' => now(), 'is_active' => false]);

        $this->getJson('/api/gallery/videos?recent_limit=2&home_limit=3')
            ->assertOk()
            ->assertJsonPath('data.recent.0.title', 'D')
            ->assertJsonPath('data.recent.1.title', 'C')
            ->assertJsonPath('data.home.0.title', 'D')
            ->assertJsonPath('data.home.1.title', 'C')
            ->assertJsonPath('data.home.2.title', 'B')
            ->assertJsonMissing(['title' => 'E']);
    }

    public function test_video_views_are_unique_per_visitor_within_twenty_four_hours(): void
    {
        $video = $this->galleryVideo();

        $this->withHeader('X-Gallery-Visitor', 'visitor_unique_1234')
            ->postJson("/api/gallery/videos/{$video->id}/view")
            ->assertOk()
            ->assertJsonPath('data.view_count', 1);

        $this->withHeader('X-Gallery-Visitor', 'visitor_unique_1234')
            ->postJson("/api/gallery/videos/{$video->id}/view")
            ->assertOk()
            ->assertJsonPath('data.view_count', 1);

        $this->withHeader('X-Gallery-Visitor', 'visitor_unique_5678')
            ->postJson("/api/gallery/videos/{$video->id}/view")
            ->assertOk()
            ->assertJsonPath('data.view_count', 2);
    }

    public function test_video_like_toggles_uniquely_per_visitor(): void
    {
        $video = $this->galleryVideo();

        $this->withHeader('X-Gallery-Visitor', 'visitor_like_1234')
            ->postJson("/api/gallery/videos/{$video->id}/like")
            ->assertOk()
            ->assertJsonPath('data.like_count', 1)
            ->assertJsonPath('data.liked_by_me', true);

        $this->withHeader('X-Gallery-Visitor', 'visitor_like_1234')
            ->postJson("/api/gallery/videos/{$video->id}/like")
            ->assertOk()
            ->assertJsonPath('data.like_count', 0)
            ->assertJsonPath('data.liked_by_me', false);
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

    private function payload(array $overrides = []): array
    {
        return [
            'title' => $overrides['title'] ?? 'Admin Video',
            'description' => $overrides['description'] ?? 'Uploaded from admin.',
            'category' => $overrides['category'] ?? 'General',
            'video_type' => $overrides['video_type'] ?? GalleryVideo::TYPE_WIDE,
            'display_order' => $overrides['display_order'] ?? 4,
            'publication_status' => $overrides['publication_status'] ?? GalleryVideo::STATUS_PUBLISHED,
            'is_active' => $overrides['is_active'] ?? true,
            'thumbnail_file' => $overrides['thumbnail_file'] ?? UploadedFile::fake()->image('thumb.jpg', 640, 360),
            'video_file' => $overrides['video_file'] ?? UploadedFile::fake()->create('video.mp4', 2048, 'video/mp4'),
        ];
    }
}
