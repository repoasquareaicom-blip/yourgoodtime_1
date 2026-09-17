<?php

namespace Tests\Unit;

use App\Services\GalleryPublicMediaService;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class GalleryPublicMediaServiceTest extends TestCase
{
    public function test_store_upload_writes_under_public_gallery_and_builds_api_url(): void
    {
        $service = $this->galleryMedia();
        $relative = $service->storeUpload(
            UploadedFile::fake()->image('cover.jpg', 320, 180),
            'thumbnail'
        );

        $this->assertMatchesRegularExpression('/^gallery\/thumbnails\/[a-f0-9-]{36}\.jpg$/', $relative);
        $this->assertTrue($service->exists($relative));
        $this->assertSame(
            'http://localhost/'.$relative,
            $service->publicUrl($relative)
        );
        $this->assertStringNotContainsString('/storage/', (string) $service->publicUrl($relative));
        $this->assertStringStartsWith(
            storage_path('framework/testing/gallery-public'),
            $service->absolutePath($relative)
        );
    }

    public function test_rejects_path_traversal_and_does_not_delete_outside_gallery(): void
    {
        $service = $this->galleryMedia();

        $this->assertFalse($service->isSafeRelativePath('../secret.jpg'));
        $this->assertFalse($service->isSafeVideoPath('gallery/videos/../thumbnails/x.mp4'));
        $service->delete('../secret.txt');
        $service->delete('storage/app/public/gallery/videos/x.mp4');

        $this->assertNull($service->publicUrl('../secret.jpg'));
        $this->assertNull($service->publicUrl('/storage/gallery/videos/x.mp4'));
    }

    public function test_default_files_land_in_laravel_public_gallery_directories(): void
    {
        config(['gallery.public_root' => public_path()]);
        $service = app(GalleryPublicMediaService::class);
        $relative = $service->put('gallery/thumbnails/verify-public-root.jpg', 'ok');
        $absolute = public_path('gallery'.DIRECTORY_SEPARATOR.'thumbnails'.DIRECTORY_SEPARATOR.'verify-public-root.jpg');

        $this->assertFileExists($absolute);
        $this->assertSame('ok', file_get_contents($absolute));
        $this->assertSame('http://localhost/gallery/thumbnails/verify-public-root.jpg', $service->publicUrl($relative));

        $service->delete($relative);
        $this->assertFileDoesNotExist($absolute);
    }
}
