<?php

namespace App\Http\Controllers;

use App\Models\GalleryVideo;
use Illuminate\Http\Response;

class GalleryVideoShareController extends Controller
{
    public function show(int $id, ?string $slug = null): Response
    {
        $video = GalleryVideo::query()->find($id);

        if (! $video || ! $video->isPubliclyShareable()) {
            return response()
                ->view('gallery.share-unavailable', [
                    'siteName' => 'Your Good Time',
                    'frontendUrl' => rtrim((string) config('app.frontend_url'), '/').'/videos',
                ], 404)
                ->header('Cache-Control', 'no-store');
        }

        $canonicalUrl = $video->shareUrl();
        $description = $this->shareDescription($video->description, $video->title);

        return response()
            ->view('gallery.share', [
                'siteName' => 'Your Good Time',
                'title' => $video->title,
                'description' => $description,
                'imageUrl' => $video->absoluteThumbnailUrl(),
                'canonicalUrl' => $canonicalUrl,
                'playerUrl' => $canonicalUrl,
            ], 200)
            ->header('Cache-Control', 'public, max-age=300');
    }

    private function shareDescription(?string $description, string $title): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags((string) $description)) ?: $title);

        if (mb_strlen($text) <= 300) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, 297)).'...';
    }
}
