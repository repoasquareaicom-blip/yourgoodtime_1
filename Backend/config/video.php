<?php

return [
    'protected_disk' => env('PROTECTED_VIDEO_DISK', 'protected_videos'),
    'token_ttl_seconds' => (int) env('PROTECTED_VIDEO_TOKEN_TTL_SECONDS', 600),
    'public_contents_fallback' => (bool) env('PROTECTED_VIDEO_PUBLIC_CONTENTS_FALLBACK', true),
];
