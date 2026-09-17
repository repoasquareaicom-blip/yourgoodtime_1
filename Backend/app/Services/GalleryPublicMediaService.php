<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use InvalidArgumentException;

class GalleryPublicMediaService
{
    public const VIDEO_DIRECTORY = 'gallery/videos';

    public const THUMBNAIL_DIRECTORY = 'gallery/thumbnails';

    public function storeUpload(UploadedFile $file, string $kind): string
    {
        $directory = $kind === 'thumbnail' ? self::THUMBNAIL_DIRECTORY : self::VIDEO_DIRECTORY;
        $allowed = $kind === 'thumbnail'
            ? ['jpg', 'jpeg', 'png', 'webp']
            : config('gallery.allowed_extensions', ['mp4', 'm4v', 'webm', 'mov']);
        $sourceName = $file->getClientOriginalName() ?: (string) $file->getExtension();
        $relativePath = $this->uniqueRelativePath($directory, $this->safeExtension($sourceName, $allowed));

        $this->copyStream($file->getRealPath(), $this->absolutePath($relativePath));

        return $relativePath;
    }

    public function uniqueRelativePath(string $directory, string $extension): string
    {
        $this->assertSafeDirectory($directory);
        $this->ensureDirectory($directory);

        return $directory.'/'.Str::uuid()->toString().'.'.$extension;
    }

    public function openWriteStream(string $relativePath)
    {
        $this->assertSafeRelativePath($relativePath);
        $this->ensureDirectory(str_replace('\\', '/', dirname($relativePath)));

        $handle = fopen($this->absolutePath($relativePath), 'wb');
        if ($handle === false) {
            throw new InvalidArgumentException('Unable to create the gallery media file.');
        }

        return $handle;
    }

    public function put(string $relativePath, string $contents): string
    {
        $this->assertSafeRelativePath($relativePath);
        $this->ensureDirectory(str_replace('\\', '/', dirname($relativePath)));
        file_put_contents($this->absolutePath($relativePath), $contents);

        return $relativePath;
    }

    public function copyFrom(string $sourcePath, string $relativePath): string
    {
        $this->assertSafeRelativePath($relativePath);
        $this->ensureDirectory(str_replace('\\', '/', dirname($relativePath)));
        $this->copyStream($sourcePath, $this->absolutePath($relativePath));

        return $relativePath;
    }

    public function get(string $relativePath): string
    {
        $this->assertSafeRelativePath($relativePath);

        return (string) file_get_contents($this->absolutePath($relativePath));
    }

    public function exists(string $relativePath): bool
    {
        return $this->isSafeRelativePath($relativePath) && is_file($this->absolutePath($relativePath));
    }

    public function size(string $relativePath): int
    {
        if (! $this->exists($relativePath)) {
            return 0;
        }

        return (int) filesize($this->absolutePath($relativePath));
    }

    public function delete(?string $relativePath): void
    {
        if (! $relativePath || ! $this->isSafeRelativePath($relativePath)) {
            return;
        }

        $absolute = $this->absolutePath($relativePath);
        if (is_file($absolute)) {
            unlink($absolute);
        }
    }

    public function files(string $directory): array
    {
        $this->assertSafeDirectory($directory);
        $path = $this->directoryPath($directory);
        if (! is_dir($path)) {
            return [];
        }

        $files = glob($path.DIRECTORY_SEPARATOR.'*') ?: [];

        return array_values(array_filter($files, 'is_file'));
    }

    public function publicUrl(?string $relativePath): ?string
    {
        if (! $relativePath || ! $this->isSafeRelativePath($relativePath)) {
            return null;
        }

        $url = rtrim((string) config('app.url'), '/').'/'.$relativePath;

        if (app()->environment('production') && str_starts_with($url, 'http://')) {
            $url = 'https://'.substr($url, strlen('http://'));
        }

        return $url;
    }

    public function absolutePath(string $relativePath): string
    {
        $this->assertSafeRelativePath($relativePath);

        return $this->root().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }

    public function isSafeVideoPath(string $path): bool
    {
        return (bool) preg_match('/^gallery\/videos\/[A-Za-z0-9._-]+$/', $path);
    }

    public function isSafeThumbnailPath(string $path): bool
    {
        return (bool) preg_match('/^gallery\/thumbnails\/[A-Za-z0-9._-]+$/', $path);
    }

    public function isSafeRelativePath(string $path): bool
    {
        return $this->isSafeVideoPath($path) || $this->isSafeThumbnailPath($path);
    }

    private function copyStream(string $sourcePath, string $destinationPath): void
    {
        $input = fopen($sourcePath, 'rb');
        $output = fopen($destinationPath, 'wb');
        if ($input === false || $output === false) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            throw new InvalidArgumentException('Unable to write the gallery media file.');
        }

        stream_copy_to_stream($input, $output);
        fclose($input);
        fclose($output);
    }

    private function ensureDirectory(string $directory): void
    {
        $this->assertSafeDirectory($directory);
        $path = $this->directoryPath($directory);
        if (! is_dir($path) && ! mkdir($path, 0755, true) && ! is_dir($path)) {
            throw new InvalidArgumentException('Unable to create the gallery media directory.');
        }
    }

    private function directoryPath(string $directory): string
    {
        return $this->root().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $directory);
    }

    private function root(): string
    {
        return rtrim((string) config('gallery.public_root', public_path()), DIRECTORY_SEPARATOR);
    }

    private function assertSafeDirectory(string $directory): void
    {
        if (! in_array($directory, [self::VIDEO_DIRECTORY, self::THUMBNAIL_DIRECTORY], true)) {
            throw new InvalidArgumentException('Invalid gallery media directory.');
        }
    }

    private function assertSafeRelativePath(string $path): void
    {
        if (! $this->isSafeRelativePath($path)) {
            throw new InvalidArgumentException('Invalid gallery media path.');
        }
    }

    private function safeExtension(string $originalFilename, array $allowed): string
    {
        $extension = strtolower((string) pathinfo(basename($originalFilename), PATHINFO_EXTENSION));
        if ($extension === 'jpeg') {
            $extension = 'jpg';
        }
        if (! in_array($extension, $allowed, true)) {
            throw new InvalidArgumentException('Unsupported gallery media type.');
        }

        return $extension;
    }
}
