<?php

return [
    'max_bytes' => 500 * 1024 * 1024,
    'max_chunk_bytes' => 6 * 1024 * 1024,
    'abandoned_after_hours' => 24,
    'allowed_extensions' => ['mp4', 'm4v', 'webm', 'mov'],
    'public_root' => env('GALLERY_PUBLIC_ROOT') ?: public_path(),
];
