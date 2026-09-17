<?php

namespace Tests\Feature;

use App\Models\GalleryVideo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GalleryVideoSharePreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_share_page_exposes_open_graph_and_twitter_metadata(): void
    {
        $video = $this->publicVideo([
            'title' => 'Why Temples Were Built?',
            'description' => 'Temples were created as sacred spaces for prayer, meditation, and spiritual connection.',
            'thumbnail_path' => 'gallery/thumbnails/temples.jpg',
        ]);

        $this->get("/gallery/share/{$video->id}/not-the-real-slug")
            ->assertOk()
            ->assertSee('<meta property="og:title" content="Why Temples Were Built?">', false)
            ->assertSee('<meta property="og:description" content="Temples were created as sacred spaces for prayer, meditation, and spiritual connection.">', false)
            ->assertSee('<meta property="og:type" content="video.other">', false)
            ->assertSee('<meta property="og:site_name" content="Your Good Time">', false)
            ->assertSee('property="og:image"', false)
            ->assertSee('http://localhost/gallery/thumbnails/temples.jpg', false)
            ->assertSee('http://localhost:5173/videos/'.$video->id.'/why-temples-were-built', false)
            ->assertSee('<meta name="twitter:card" content="summary_large_image">', false)
            ->assertSee('<meta name="twitter:title" content="Why Temples Were Built?">', false)
            ->assertSee('name="twitter:image"', false);
    }

    public function test_hidden_and_missing_videos_do_not_expose_share_metadata(): void
    {
        $hidden = $this->publicVideo([
            'title' => 'Secret Hidden Temple Video',
            'description' => 'Do not leak this hidden description.',
            'thumbnail_path' => 'gallery/thumbnails/secret.jpg',
            'is_active' => false,
        ]);

        $this->get("/gallery/share/{$hidden->id}/secret-hidden-temple-video")
            ->assertNotFound()
            ->assertDontSee('Secret Hidden Temple Video', false)
            ->assertDontSee('Do not leak this hidden description.', false)
            ->assertDontSee('secret.jpg', false)
            ->assertSee('This video is no longer available.', false);

        $this->get('/gallery/share/99999/missing-video')
            ->assertNotFound()
            ->assertDontSee('property="og:image"', false);
    }

    public function test_draft_videos_are_not_shareable(): void
    {
        $draft = $this->publicVideo([
            'title' => 'Draft Preview Only',
            'publication_status' => GalleryVideo::STATUS_DRAFT,
            'published_at' => null,
        ]);

        $this->get("/gallery/share/{$draft->id}")
            ->assertNotFound()
            ->assertDontSee('Draft Preview Only', false);
    }

    private function publicVideo(array $overrides = []): GalleryVideo
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
            'published_at' => array_key_exists('published_at', $overrides) ? $overrides['published_at'] : now(),
            'created_by' => $overrides['created_by'] ?? null,
        ]);
    }
}
