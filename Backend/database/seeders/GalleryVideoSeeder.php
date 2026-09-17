<?php

namespace Database\Seeders;

use App\Models\GalleryVideo;
use App\Services\GalleryPublicMediaService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class GalleryVideoSeeder extends Seeder
{
    public function run(): void
    {
        $frontendVideoDir = base_path('../Frontend/src/assets/videos');
        if (! File::isDirectory($frontendVideoDir)) {
            return;
        }

        $media = app(GalleryPublicMediaService::class);

        $rows = [
            ['Why Temples Were Built?', 'Why temples were built? Do you know the secret behind them? Watch this short reveal.', 'Temple', 'SHORT', '1.jpg', 'intro-1.mp4', '2026-07-03 09:00:00'],
            ['Career Guidance', 'Understand the best career windows, growth cycles, and practical timing for major decisions.', 'Career', 'SHORT', '2.jpg', 'intro-2.mp4', '2026-06-29 09:00:00'],
            ['Relationship Insights', 'See emotional compatibility trends and communication patterns that shape stronger bonds.', 'Relationship', 'WIDE', '3.jpg', 'intro-1.mp4', '2026-06-25 09:00:00'],
            ['Wealth Forecast', 'A compact financial forecast with timing cues for savings, investments, and risk awareness.', 'Wealth', 'WIDE', '4.jpg', 'intro-2.mp4', '2026-06-19 09:00:00'],
            ['Family Harmony', 'Practical guidance for family balance, conflict resolution, and more peaceful daily routines.', 'Family', 'WIDE', '5.jpg', 'intro-1.mp4', '2026-06-15 09:00:00'],
            ['Temple Mysteries', 'Explore hidden temple architecture clues and spiritual science in one quick session.', 'Temple', 'WIDE', '6.jpg', 'intro-2.mp4', '2026-06-11 09:00:00'],
            ['Spiritual Signs', 'Decode daily signs and symbols that many people ignore in spiritual life.', 'Spiritual', 'WIDE', '7.png', 'intro-1.mp4', '2026-06-08 09:00:00'],
            ['Energy and Time', 'Learn how timing and personal energy patterns affect outcomes and decisions.', 'Spiritual', 'WIDE', '8.jpg', 'intro-2.mp4', '2026-06-02 09:00:00'],
            ['Mantra Power', 'A beginner-friendly guide to mantra practice for clarity, focus, and peace.', 'Spiritual', 'WIDE', '9.png', 'intro-1.mp4', '2026-05-29 09:00:00'],
            ['Destiny Cycles', 'Understand repeating life cycles and how to use them to plan major moves.', 'Life Timing', 'WIDE', '10.jpg', 'intro-2.mp4', '2026-05-21 09:00:00'],
            ['Temple Science', 'Explore the design science behind temples and what makes them powerful spaces.', 'Temple', 'WIDE', '11.jpg', 'intro-1.mp4', '2026-05-12 09:00:00'],
        ];

        foreach ($rows as $index => [$title, $description, $category, $type, $thumbnail, $video, $publishedAt]) {
            $thumbnailPath = $this->copyAsset($media, $frontendVideoDir, $thumbnail, GalleryPublicMediaService::THUMBNAIL_DIRECTORY);
            $videoPath = $this->copyAsset($media, $frontendVideoDir, $video, GalleryPublicMediaService::VIDEO_DIRECTORY);

            if (! $videoPath) {
                continue;
            }

            GalleryVideo::query()->updateOrCreate(
                ['title' => $title],
                [
                    'description' => $description,
                    'category' => $category,
                    'video_type' => $type,
                    'video_path' => $videoPath,
                    'thumbnail_path' => $thumbnailPath,
                    'display_order' => $index,
                    'publication_status' => GalleryVideo::STATUS_PUBLISHED,
                    'is_active' => true,
                    'published_at' => $publishedAt,
                ]
            );
        }
    }

    private function copyAsset(GalleryPublicMediaService $media, string $sourceDir, string $filename, string $targetDir): ?string
    {
        $source = $sourceDir.DIRECTORY_SEPARATOR.$filename;
        if (! File::isFile($source)) {
            return null;
        }

        $targetName = Str::slug(pathinfo($filename, PATHINFO_FILENAME)).'.'.strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        $targetPath = $targetDir.'/'.$targetName;

        if (! $media->exists($targetPath)) {
            $media->copyFrom($source, $targetPath);
        }

        return $targetPath;
    }
}
