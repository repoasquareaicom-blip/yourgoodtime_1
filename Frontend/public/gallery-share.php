<?php

$id = isset($_GET['id']) ? preg_replace('/\D+/', '', (string) $_GET['id']) : '';
if ($id === '') {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo 'This video is no longer available.';
    exit;
}

$host = $_SERVER['HTTP_HOST'] ?? '';
$override = getenv('GALLERY_SHARE_API_URL');
if (is_string($override) && trim($override) !== '') {
    $apiBase = rtrim(trim($override), '/');
} elseif (preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/', $host)
    || preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[0-1])\.)/', $host)) {
    $apiBase = 'http://127.0.0.1:8000';
} else {
    $apiBase = 'https://api.yourgoodtime.in';
}

$shareUrl = $apiBase.'/gallery/share/'.$id;
$context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => 'User-Agent: '.(isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'WhatsApp')."\r\n",
        'timeout' => 8,
        'ignore_errors' => true,
    ],
]);

$html = @file_get_contents($shareUrl, false, $context);
$statusLine = $http_response_header[0] ?? 'HTTP/1.1 502 Bad Gateway';
$statusCode = preg_match('/\s(\d{3})\s/', $statusLine, $matches) ? (int) $matches[1] : 502;

if ($html === false) {
    http_response_code(502);
    header('Content-Type: text/html; charset=utf-8');
    echo 'Unable to load video preview.';
    exit;
}

http_response_code($statusCode >= 100 ? $statusCode : 502);
header('Content-Type: text/html; charset=utf-8');
echo $html;
