<?php

namespace Tests;

use App\Services\GalleryPublicMediaService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\File;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $root = storage_path('framework/testing/gallery-public');
        File::deleteDirectory($root);
        File::ensureDirectoryExists($root);
        config(['gallery.public_root' => $root]);
    }

    protected function galleryMedia(): GalleryPublicMediaService
    {
        return app(GalleryPublicMediaService::class);
    }
}
